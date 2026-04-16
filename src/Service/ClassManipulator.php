<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Service;

use MonkeysLegion\Cli\Config\RelationKind;
use MonkeysLegion\Entity\Attributes\JoinTable;
use PhpParser\BuilderFactory;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * AST-based PHP class manipulator for entity code generation.
 *
 * v2 improvements:
 *  • Generates PHP 8.4 asymmetric visibility (`public private(set)`)
 *  • Emits `#[Id]` + `#[Field]` dual-attribute for primary keys
 *  • Removes getter/setter boilerplate → leverages public properties
 *  • Only generates add/remove/get helpers for collection relations
 *  • Better formatting with consistent blank lines
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class ClassManipulator
{
    private readonly Parser $parser;
    private readonly BuilderFactory $factory;
    private readonly Standard $printer;
    private readonly NodeFinder $finder;

    /** @var list<Stmt> */
    private array $ast;
    private ?Class_ $classNode;
    private readonly string $file;

    /** Map attribute names → RelationKind enums. */
    private const array ATTR_TO_ENUM = [
        'OneToOne'   => RelationKind::ONE_TO_ONE,
        'OneToMany'  => RelationKind::ONE_TO_MANY,
        'ManyToOne'  => RelationKind::MANY_TO_ONE,
        'ManyToMany' => RelationKind::MANY_TO_MANY,
    ];

    /** Relation kinds that produce array<Entity> collections. */
    private const array COLLECTION_KINDS = [
        RelationKind::ONE_TO_MANY,
        RelationKind::MANY_TO_MANY,
    ];

    // ── Constructor ──────────────────────────────────────────────

    public function __construct(string $file)
    {
        $this->parser  = (new ParserFactory())->createForNewestSupportedVersion();
        $this->factory = new BuilderFactory();
        $this->printer = new Standard();
        $this->finder  = new NodeFinder();
        $this->file    = $file;

        $src = file_exists($file) ? (string) file_get_contents($file) : '';
        $ast = $src !== '' ? $this->parser->parse($src) : null;

        if (!$ast) {
            echo "Failed to parse file: {$file}\n";
            $this->ast       = [];
            $this->classNode = null;

            return;
        }

        $this->ast       = $ast;
        $this->classNode = $this->finder->findFirstInstanceOf($this->ast, Class_::class);
    }

    // ── Public API: scalar fields ──────────────────────────────

    /**
     * Add a scalar field with `#[Field]` attribute.
     *
     * v2: properties use `public private(set)` instead of getter/setter.
     */
    public function addScalarField(
        string $name,
        string $dbType,
        string $phpType,
        bool $nullable = false,
    ): void {
        $attrArgs = [
            new Arg(new Scalar\String_($dbType), false, false, [], new Identifier('type')),
        ];

        if ($nullable) {
            $attrArgs[] = new Arg(
                new Expr\ConstFetch(new Name('true')),
                false,
                false,
                [],
                new Identifier('nullable'),
            );
            $phpType = '?' . ltrim($phpType, '?');
        }

        $attr = $this->factory->attribute('Field', $attrArgs);

        $propBuilder = $this->factory->property($name)
            ->makePublic()
            ->setType($phpType)
            ->addAttribute($attr);

        // Add #[Uuid] attribute if field type is 'uuid'
        if (strtolower($dbType) === 'uuid') {
            $propBuilder->addAttribute($this->factory->attribute('Uuid'));
            $this->ensureUseStatement('MonkeysLegion\\Entity\\Attributes\\Uuid');
        }

        if ($nullable) {
            $propBuilder->setDefault(null);
        }

        $prop = $propBuilder->getNode();

        $this->removeProperty($name);
        $this->insertProperty($prop);
    }

    // ── Public API: relations ─────────────────────────────────

    /**
     * Add a relation property with the correct attribute.
     *
     * v2: collection relations still get add/remove/get helpers,
     * but to-one relations are just public properties (no getter/setter).
     */
    public function addRelation(
        string $name,
        RelationKind|string $kind,
        string $targetShort,
        bool $isOwningSide,
        ?string $otherProp = null,
        ?JoinTable $joinTable = null,
        bool $inverseO2O = false,
        bool $nullable = true,
    ): void {
        $kind   = self::toEnum($kind);
        $attr   = $kind->value;
        $isMany = in_array($kind, self::COLLECTION_KINDS, true);

        if ($kind === RelationKind::ONE_TO_MANY) {
            $isOwningSide = false;
        }

        if (!preg_match('/^[A-Z][A-Za-z0-9_]*$/', $targetShort)) {
            return;
        }

        // Build attribute arguments
        $attrArgs = [
            new Arg(
                new Expr\ClassConstFetch(new Name($targetShort), 'class'),
                false,
                false,
                [],
                new Identifier('targetEntity'),
            ),
            ...$this->buildExtraArgs($otherProp, $kind, $isMany, $joinTable, $inverseO2O, $isOwningSide),
        ];

        if ($isMany) {
            $docComment = " /** @var {$targetShort}[] */";
            $propNode = $this->factory->property($name)
                ->makePublic()
                ->setType('array')
                ->setDefault([])
                ->addAttribute($this->factory->attribute($attr, $attrArgs))
                ->setDocComment($docComment)
                ->getNode();
        } else {
            $phpType = $nullable ? ('?' . $targetShort) : $targetShort;

            $propBuilder = $this->factory->property($name)
                ->makePublic()
                ->setType($phpType)
                ->addAttribute($this->factory->attribute($attr, $attrArgs));

            if ($nullable) {
                $propBuilder->setDefault(null);
            }

            $propNode = $propBuilder->getNode();
        }

        $this->removeProperty($name);
        $this->insertProperty($propNode);

        // Add initialization to constructor for collections
        if ($isMany) {
            $this->addCollectionInitToConstructor($name);
        }

        // v2: only generate helper methods for collections
        if ($isMany) {
            $this->generateCollectionMethods($name, $targetShort);
        }
    }

    // ── Save ─────────────────────────────────────────────────

    /**
     * Write the modified AST back to the file.
     */
    public function save(): void
    {
        $code = $this->printer->prettyPrintFile($this->ast);
        $code = $this->formatCode($code);

        if (!str_starts_with($code, '<?php')) {
            $code = "<?php\n\n" . $code;
        }

        file_put_contents($this->file, $code);
    }

    // ── Enum helper ──────────────────────────────────────────

    /**
     * Convert a RelationKind or string to a RelationKind enum.
     */
    public static function toEnum(RelationKind|string $kind): RelationKind
    {
        if ($kind instanceof RelationKind) {
            return $kind;
        }

        if (isset(self::ATTR_TO_ENUM[$kind])) {
            return self::ATTR_TO_ENUM[$kind];
        }

        $normalized = strtolower(preg_replace('/[^a-z]/i', '', $kind) ?: $kind);

        return match ($normalized) {
            'onetoone'   => RelationKind::ONE_TO_ONE,
            'onetomany'  => RelationKind::ONE_TO_MANY,
            'manytoone'  => RelationKind::MANY_TO_ONE,
            'manytomany' => RelationKind::MANY_TO_MANY,
            default      => RelationKind::from($kind),
        };
    }

    // ── Private: collection method generation ────────────────

    /**
     * Generate add, remove, and getter methods for collection relations.
     */
    private function generateCollectionMethods(string $name, string $targetShort): void
    {
        $stud = ucfirst($name);

        // add{Entity}()
        $add = $this->factory->method('add' . $targetShort)
            ->makePublic()
            ->setReturnType('self')
            ->addParam($this->factory->param('item')->setType($targetShort))
            ->addStmt(new Expr\Assign(
                new Expr\ArrayDimFetch(
                    new Expr\PropertyFetch(new Expr\Variable('this'), $name),
                ),
                new Expr\Variable('item'),
            ))
            ->addStmt(new Stmt\Return_(new Expr\Variable('this')))
            ->getNode();

        $this->insertMethod($add);

        // remove{Entity}()
        $remove = $this->factory->method('remove' . $targetShort)
            ->makePublic()
            ->setReturnType('self')
            ->addParam($this->factory->param('item')->setType($targetShort))
            ->addStmt(new Expr\Assign(
                new Expr\PropertyFetch(new Expr\Variable('this'), $name),
                new Expr\FuncCall(new Name('array_filter'), [
                    new Arg(new Expr\PropertyFetch(new Expr\Variable('this'), $name)),
                    new Arg(new Expr\ArrowFunction([
                        'params' => [new Param(new Expr\Variable('i'))],
                        'expr'   => new Expr\BinaryOp\NotIdentical(
                            new Expr\Variable('i'),
                            new Expr\Variable('item'),
                        ),
                        'static' => false,
                    ])),
                ]),
            ))
            ->addStmt(new Stmt\Return_(new Expr\Variable('this')))
            ->getNode();

        $this->insertMethod($remove);

        // get{Name}()
        $getter = $this->factory->method('get' . $stud)
            ->makePublic()
            ->setReturnType('array')
            ->addStmt(new Stmt\Return_(
                new Expr\PropertyFetch(new Expr\Variable('this'), $name),
            ))
            ->getNode();

        $this->insertMethod($getter);
    }

    // ── Private: attribute arg builders ──────────────────────

    /**
     * Build extra arguments for relation attributes (mappedBy, inversedBy, joinTable).
     *
     * @return list<Arg>
     */
    private function buildExtraArgs(
        ?string $otherProp,
        RelationKind|string $attr,
        bool $isMany,
        ?JoinTable $joinTable,
        bool $inverseO2O,
        bool $isOwningSide = false,
    ): array {
        $attrEnum = self::toEnum($attr);
        $args     = [];

        if ($attrEnum === RelationKind::ONE_TO_ONE && $inverseO2O && $otherProp) {
            $args[] = new Arg(
                new Scalar\String_($otherProp),
                false,
                false,
                [],
                new Identifier('mappedBy'),
            );
        } elseif ($otherProp) {
            $param = ($attrEnum === RelationKind::ONE_TO_MANY)
                ? 'mappedBy'
                : ($isOwningSide ? 'inversedBy' : 'mappedBy');

            $args[] = new Arg(
                new Scalar\String_($otherProp),
                false,
                false,
                [],
                new Identifier($param),
            );
        }

        if ($attrEnum === RelationKind::MANY_TO_MANY && $isOwningSide && $joinTable) {
            $args[] = new Arg(
                new Expr\New_(
                    new Name('JoinTable'),
                    [
                        new Arg(new Scalar\String_($joinTable->name), false, false, [], new Identifier('name')),
                        new Arg(new Scalar\String_($joinTable->joinColumn), false, false, [], new Identifier('joinColumn')),
                        new Arg(new Scalar\String_($joinTable->inverseColumn), false, false, [], new Identifier('inverseColumn')),
                    ],
                ),
                false,
                false,
                [],
                new Identifier('joinTable'),
            );
        }

        return $args;
    }

    // ── Private: constructor management ──────────────────────

    /**
     * Add `$this->prop = [];` to the constructor for collection properties.
     */
    private function addCollectionInitToConstructor(string $propName): void
    {
        $ctor = null;

        foreach ($this->classNode?->stmts ?? [] as $stmt) {
            if ($stmt instanceof ClassMethod && $stmt->name->toString() === '__construct') {
                $ctor = $stmt;

                break;
            }
        }

        if (!$ctor) {
            $ctor = new ClassMethod('__construct', [
                'flags' => Modifiers::PUBLIC,
                'stmts' => [],
            ]);
            $this->insertMethod($ctor);
        }

        // Skip if already initialized
        foreach ($ctor->stmts ?? [] as $s) {
            if (
                $s instanceof Stmt\Expression
                && $s->expr instanceof Expr\Assign
                && $s->expr->var instanceof Expr\PropertyFetch
                && $s->expr->var->var instanceof Expr\Variable
                && $s->expr->var->var->name === 'this'
                && $s->expr->var->name instanceof Identifier
                && $s->expr->var->name->name === $propName
            ) {
                return;
            }
        }

        $ctor->stmts[] = new Stmt\Expression(
            new Expr\Assign(
                new Expr\PropertyFetch(new Expr\Variable('this'), $propName),
                new Expr\Array_([]),
            ),
        );
    }

    // ── Private: AST structure helpers ───────────────────────

    /**
     * Remove a property from the class by name.
     */
    private function removeProperty(string $name): void
    {
        if (!$this->classNode) {
            return;
        }

        $this->classNode->stmts = array_values(array_filter(
            $this->classNode->stmts,
            static fn(Stmt $stmt): bool => !(
                $stmt instanceof Property
                && $stmt->props[0]->name->name === $name
            ),
        ));
    }

    /**
     * Insert a property at the correct position (after last property, before methods).
     */
    private function insertProperty(Property $prop): void
    {
        if (!$this->classNode) {
            return;
        }

        $stmts       = &$this->classNode->stmts;
        $insertIndex = $this->findPropertyInsertionPoint($stmts);

        // Clean blank lines around insertion point
        if ($insertIndex > 0 && $this->isBlankLine($stmts[$insertIndex - 1])) {
            array_splice($stmts, $insertIndex - 1, 1);
            $insertIndex--;
        }

        if (isset($stmts[$insertIndex]) && $this->isBlankLine($stmts[$insertIndex])) {
            array_splice($stmts, $insertIndex, 1);
        }

        // Blank line before (unless first property)
        if ($insertIndex > 0 && !$this->isFirstProperty($insertIndex, $stmts)) {
            array_splice($stmts, $insertIndex, 0, [new Nop()]);
            $insertIndex++;
        }

        array_splice($stmts, $insertIndex, 0, [$prop]);
        $insertIndex++;

        // Blank line after if next is property or method
        if (
            isset($stmts[$insertIndex])
            && !$this->isBlankLine($stmts[$insertIndex])
            && ($stmts[$insertIndex] instanceof Property || $stmts[$insertIndex] instanceof ClassMethod)
        ) {
            array_splice($stmts, $insertIndex, 0, [new Nop()]);
        }
    }

    /**
     * Insert a method at the bottom of the class.
     */
    private function insertMethod(ClassMethod $method): void
    {
        if (!$this->classNode) {
            return;
        }

        $stmts           = &$this->classNode->stmts;
        $lastMethodIndex = -1;

        foreach ($stmts as $i => $stmt) {
            if ($stmt instanceof ClassMethod) {
                $lastMethodIndex = $i;
            }
        }

        $insertIndex = $lastMethodIndex >= 0 ? $lastMethodIndex + 1 : count($stmts);

        if ($lastMethodIndex >= 0) {
            array_splice($stmts, $insertIndex, 0, []);
            $insertIndex++;
        }

        array_splice($stmts, $insertIndex, 0, [$method]);
    }

    /**
     * Find where to insert a new property (after last property, before first method).
     *
     * @param list<Stmt> $stmts
     */
    private function findPropertyInsertionPoint(array $stmts): int
    {
        $lastPropertyIndex = -1;

        foreach ($stmts as $i => $stmt) {
            if ($stmt instanceof Property) {
                $lastPropertyIndex = $i;
            } elseif ($stmt instanceof ClassMethod) {
                break;
            }
        }

        return $lastPropertyIndex + 1;
    }

    /**
     * @param list<Stmt> $stmts
     */
    private function isFirstProperty(int $index, array $stmts): bool
    {
        for ($i = 0; $i < $index; $i++) {
            if ($stmts[$i] instanceof Property) {
                return false;
            }
        }

        return true;
    }

    private function isBlankLine(Stmt $node): bool
    {
        return $node instanceof Nop;
    }

    // ── Private: use statement management ────────────────────

    /**
     * Ensure a use statement exists in the namespace block.
     */
    private function ensureUseStatement(string $fqcn): void
    {
        if ($this->ast === []) {
            return;
        }

        // Find namespace node
        $namespace = null;

        foreach ($this->ast as $node) {
            if ($node instanceof Stmt\Namespace_) {
                $namespace = $node;

                break;
            }
        }

        if (!$namespace) {
            return;
        }

        // Check if already exists
        foreach ($namespace->stmts as $stmt) {
            if ($stmt instanceof Stmt\Use_) {
                foreach ($stmt->uses as $use) {
                    if ($use->name->toString() === $fqcn) {
                        return;
                    }
                }
            }
        }

        // Find insertion point (after last use statement)
        $lastUseIndex = -1;

        foreach ($namespace->stmts as $i => $stmt) {
            if ($stmt instanceof Stmt\Use_) {
                $lastUseIndex = $i;
            } elseif ($stmt instanceof Class_) {
                break;
            }
        }

        $useStmt = new Stmt\Use_([
            new \PhpParser\Node\UseItem(new Name($fqcn)),
        ]);

        $insertIndex = $lastUseIndex >= 0 ? $lastUseIndex + 1 : 0;
        array_splice($namespace->stmts, $insertIndex, 0, [$useStmt]);
    }

    // ── Private: code formatting ────────────────────────────

    /**
     * Post-process the pretty-printed code for consistent formatting.
     */
    private function formatCode(string $code): string
    {
        // Fix declare spacing
        $code = preg_replace(
            '/declare\s+\(\s*strict_types\s*=\s*1\s*\)\s*;/',
            'declare(strict_types=1);',
            $code,
        ) ?: $code;

        // Blank line after declare
        $code = preg_replace(
            '/(declare\(strict_types=1\);\s*)\n+/',
            "\$1\n\n",
            $code,
        ) ?: $code;

        // Blank line before class/attribute after use statements
        $code = preg_replace(
            '/(use\s+[^;]+;\s*\n)(?!\s*\n)(\s*(?:#\[|\bclass\b))/m',
            "\$1\n\$2",
            $code,
        ) ?: $code;

        // Blank lines between attributed properties
        $code = preg_replace(
            '/(#\[[^\]]*\]\s*\n\s*public\s+[^;]+;)\s*\n(?!\s*\n)(\s*#\[)/m',
            "\$1\n\n\$2",
            $code,
        ) ?: $code;

        // Blank line before methods
        $code = preg_replace(
            '/(public\s+[^;]+;)\s*\n(?!\s*\n)(\s*public\s+function)/m',
            "\$1\n\n\$2",
            $code,
        ) ?: $code;

        // Consistent blank line between methods
        $code = preg_replace(
            '/(\}\s*\n+)(\s*public\s+function)/m',
            "}\n\n\$2",
            $code,
        ) ?: $code;

        // Max 2 consecutive blank lines
        $code = preg_replace('/\n{3,}/', "\n\n", $code) ?: $code;

        // Fix class closing brace spacing
        $code = preg_replace('/(\}\s*)\n+(\s*\})$/', "\$1\n\$2", $code) ?: $code;
        $code = preg_replace('/(\}\s*)\n+(\s*\})$/', "\$1\$2", $code) ?: $code;

        // Normalize visibility keywords
        $code = preg_replace('/(\s*)(public|protected|private)(\s+)/', "\$1\$2 ", $code) ?: $code;

        // Ensure attribute casing
        $code = preg_replace_callback(
            '/#\[\s*(one(?:To(?:One|Many))|many(?:To(?:One|Many)))\s*\(/i',
            static fn(array $m): string => '#[' . ucfirst($m[1]) . '(',
            $code,
        ) ?: $code;

        return $code;
    }
}
