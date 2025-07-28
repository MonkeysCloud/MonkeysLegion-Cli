<?php

namespace MonkeysLegion\Cli\Service;

use MonkeysLegion\Cli\Config\RelationKind;
use PhpParser\Node\Stmt\Nop;
use PhpParser\ParserFactory;
use PhpParser\BuilderFactory;
use PhpParser\PrettyPrinter\Standard;
use PhpParser\NodeFinder;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node;
use MonkeysLegion\Entity\Attributes\JoinTable;
use PhpParser\Modifiers;
use PhpParser\Parser;

/**
 * ClassManipulator is a utility class for manipulating PHP classes using the PhpParser library.
 * It allows adding fields, relations, and methods to classes, and saving the modified class back to a file.
 */
class ClassManipulator
{
    /** @var Parser */
    private $parser;

    /** @var BuilderFactory */
    private $builderFactory;

    /** @var Standard */
    private $prettyPrinter;

    /** @var NodeFinder */
    private $nodeFinder;

    /** @var Node\Stmt[] */
    private $ast;

    /** @var Class_|null */
    private $classNode;

    /** @var string */
    private $file;

    /**
     * Mapping of attribute names to RelationKind enums.
     * This is used to convert string attributes to enum values.
     */
    private const array ATTR_TO_ENUM = [
        'OneToOne'   => RelationKind::ONE_TO_ONE,
        'OneToMany'  => RelationKind::ONE_TO_MANY,
        'ManyToOne'  => RelationKind::MANY_TO_ONE,
        'ManyToMany' => RelationKind::MANY_TO_MANY,
    ];

    /**
     * Collection kinds that are treated as arrays (ONE_TO_MANY, MANY_TO_MANY).
     * This is used to determine how to handle relations in the class.
     */
    private const array COLLECTION_KINDS = [
        RelationKind::ONE_TO_MANY,
        RelationKind::MANY_TO_MANY,
    ];

    /**
     * ClassManipulator constructor.
     * Initializes the parser, builder factory, pretty printer, and node finder.
     * Parses the given file to create an AST and finds the first class node.
     *
     * @param string $file The path to the PHP file to manipulate.
     */
    public function __construct(string $file)
    {
        $this->parser = new ParserFactory()->createForNewestSupportedVersion();
        $this->builderFactory = new BuilderFactory();
        $this->prettyPrinter = new Standard();
        $this->nodeFinder = new NodeFinder();
        $this->file = $file;

        $src = file_exists($file) ? file_get_contents($file) : '';
        $ast = $src ? $this->parser->parse($src) : null;
        if (!$ast) {
            echo "Failed to parse file: $file\n";
            return;
        }
        $this->ast = $ast;
        $this->classNode = $this->nodeFinder->findFirstInstanceOf($this->ast, Class_::class);
    }

    /**
     * Adds a scalar field to the class with the specified name, database type, and PHP type.
     * This will create a public property with a Field attribute, and generate getter/setter methods.
     *
     * @param string $name The name of the field.
     * @param string $dbType The database type (e.g., 'integer', 'string').
     * @param string $phpType The PHP type (e.g., 'int', 'string').
     */
    public function addScalarField(string $name, string $dbType, string $phpType): void
    {
        $attr = $this->builderFactory->attribute('Field', ['type' => $dbType]);
        $prop = $this->builderFactory->property($name)
            ->makePublic()
            ->setType($phpType)
            ->addAttribute($attr)
            ->getNode();

        $this->removeProperty($name);
        $this->insertProperty($prop);

        // Getter
        $getter = $this->builderFactory->method('get' . ucfirst($name))
            ->makePublic()
            ->setReturnType($phpType)
            ->addStmt(new Node\Stmt\Return_(
                new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $name)
            ))
            ->getNode();

        $this->insertMethod($getter);

        // Setter
        $setter = $this->builderFactory->method('set' . ucfirst($name))
            ->makePublic()
            ->setReturnType('self')
            ->addParam($this->builderFactory->param($name)->setType($phpType))
            ->addStmt(new Node\Expr\Assign(
                new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $name),
                new Node\Expr\Variable($name)
            ))
            ->addStmt(new Node\Stmt\Return_(new Node\Expr\Variable('this')))
            ->getNode();

        $this->insertMethod($setter);
    }

    /**
     * Adds a relation to the class with the specified parameters.
     * This will create a public property with the appropriate relation attribute,
     * and generate methods for accessing and modifying the relation.
     *
     * @param string $name The name of the relation property.
     * @param RelationKind|string $kind The kind of relation (e.g., ONE_TO_ONE, MANY_TO_MANY).
     * @param string $targetShort The short name of the target entity class.
     * @param bool $isOwningSide Whether this is the owning side of the relation.
     * @param string|null $otherProp The name of the other property in the related entity, if applicable.
     * @param JoinTable|null $joinTable The join table definition for MANY_TO_MANY relations, if applicable.
     * @param bool $inverseO2O Whether this is an inverse one-to-one relation.
     */
    public function addRelation(
        string $name,
        RelationKind|string $kind,
        string $targetShort,
        bool $isOwningSide,
        ?string $otherProp = null,
        ?JoinTable $joinTable = null,
        bool $inverseO2O = false
    ): void {
        $kind   = self::toEnum($kind);
        $attr   = $kind->value;
        $isMany = in_array($kind, self::COLLECTION_KINDS, true);

        if (!preg_match('/^[A-Z][A-Za-z0-9_]*$/', $targetShort)) {
            return;
        }

        // Build extra attribute arguments
        $extra = '';
        if ($kind === RelationKind::ONE_TO_ONE && $inverseO2O && $otherProp) {
            $extra .= ", mappedBy: '{$otherProp}'";
        } elseif ($otherProp) {
            // owning side ⇒ inversedBy; inverse side ⇒ mappedBy
            $extra .= $isOwningSide
                ? ", inversedBy: '{$otherProp}'"
                : ", mappedBy: '{$otherProp}'";
        }
        if ($kind === RelationKind::MANY_TO_MANY && $isOwningSide && $joinTable) {
            $extra .= ", joinTable: new JoinTable(name: '{$joinTable->name}', joinColumn: '{$joinTable->joinColumn}', inverseColumn: '{$joinTable->inverseColumn}')";
        }

        // Build docblock and property as string (for pretty output)
        $props = [];
        $ctor = [];
        if ($isMany) {
            $props[] = "    /** @var {$targetShort}[] */";
            $props[] = "    #[{$attr}(targetEntity: {$targetShort}::class{$extra})]";
            $props[] = "    public array \${$name};";
            $ctor[]  = "        \$this->{$name} = [];";

            // Insert docblock as comment
            $docComment = " /** @var {$targetShort}[] */";
            $propNode = $this->builderFactory->property($name)
                ->makePublic()
                ->setType('array')
                ->setDefault([])
                ->addAttribute(
                    $this->builderFactory->attribute($attr, [
                        new Node\Arg(
                            new Node\Expr\ClassConstFetch(new Node\Name($targetShort), 'class'),
                            false,
                            false,
                            [],
                            new Node\Identifier('targetEntity')
                        ),
                        // extra args as named arguments
                        ...$this->buildExtraArgs($otherProp, $kind, $isMany, $joinTable, $inverseO2O, $isOwningSide)
                    ])
                )
                ->setDocComment($docComment)
                ->getNode();
        } else {
            $props[] = "    #[{$attr}(targetEntity: {$targetShort}::class{$extra})]";
            $props[] = "    public ?{$targetShort} \${$name} = null;";

            $propNode = $this->builderFactory->property($name)
                ->makePublic()
                ->setType('?' . $targetShort)
                ->setDefault(null)
                ->addAttribute(
                    $this->builderFactory->attribute($attr, [
                        new Node\Arg(
                            new Node\Expr\ClassConstFetch(new Node\Name($targetShort), 'class'),
                            false,
                            false,
                            [],
                            new Node\Identifier('targetEntity')
                        ),
                        ...$this->buildExtraArgs($otherProp, $kind, $isMany, $joinTable, $inverseO2O, $isOwningSide)
                    ])
                )
                ->getNode();
        }
        $this->removeProperty($name);
        $this->insertProperty($propNode);

        // Add initialization to constructor for collections, but do NOT overwrite existing constructor
        if ($isMany) {
            $this->addCollectionInitToConstructor($name);
        }

        // Methods
        $stud = ucfirst($name);
        if ($isMany) {
            // add
            $add = $this->builderFactory->method('add' . $targetShort)
                ->makePublic()
                ->setReturnType('self')
                ->addParam($this->builderFactory->param('item')->setType($targetShort))
                ->addStmt(new Node\Expr\Assign(
                    new Node\Expr\ArrayDimFetch(
                        new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $name)
                    ),
                    new Node\Expr\Variable('item')
                ))
                ->addStmt(new Node\Stmt\Return_(new Node\Expr\Variable('this')))
                ->getNode();
            $this->insertMethod($add);

            // remove
            $remove = $this->builderFactory->method('remove' . $targetShort)
                ->makePublic()
                ->setReturnType('self')
                ->addParam($this->builderFactory->param('item')->setType($targetShort))
                ->addStmt(new Node\Expr\Assign(
                    new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $name),
                    new Node\Expr\FuncCall(new Node\Name('array_filter'), [
                        new Node\Arg(new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $name)),
                        new Node\Arg(new Node\Expr\ArrowFunction([
                            'params' => [new Node\Param(new Node\Expr\Variable('i'))],
                            'expr' => new Node\Expr\BinaryOp\NotIdentical(
                                new Node\Expr\Variable('i'),
                                new Node\Expr\Variable('item')
                            ),
                            'static' => false
                        ]))
                    ])
                ))
                ->addStmt(new Node\Stmt\Return_(new Node\Expr\Variable('this')))
                ->getNode();
            $this->insertMethod($remove);

            // getter
            $getter = $this->builderFactory->method('get' . $stud)
                ->makePublic()
                ->setReturnType('array')
                ->addStmt(new Node\Stmt\Return_(
                    new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $name)
                ))
                ->getNode();
            $this->insertMethod($getter);
        } else {
            // getter
            $getter = $this->builderFactory->method('get' . $stud)
                ->makePublic()
                ->setReturnType('?' . $targetShort)
                ->addStmt(new Node\Stmt\Return_(
                    new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $name)
                ))
                ->getNode();
            $this->insertMethod($getter);

            // setter
            $setter = $this->builderFactory->method('set' . $stud)
                ->makePublic()
                ->setReturnType('self')
                ->addParam($this->builderFactory->param($name)->setType($targetShort))
                ->addStmt(new Node\Expr\Assign(
                    new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $name),
                    new Node\Expr\Variable($name)
                ))
                ->addStmt(new Node\Stmt\Return_(new Node\Expr\Variable('this')))
                ->getNode();
            $this->insertMethod($setter);

            // remove/unset
            $remove = $this->builderFactory->method('remove' . $stud)
                ->makePublic()
                ->setReturnType('self')
                ->addStmt(
                    new Node\Expr\Assign(
                        new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $name),
                        new Node\Expr\ConstFetch(new Node\Name('null'))
                    )
                )
                ->addStmt(new Node\Stmt\Return_(new Node\Expr\Variable('this')))
                ->getNode();
            $this->insertMethod($remove);
        }
    }

    /**
     * Builds the extra arguments for the relation attribute based on the provided parameters.
     * This handles special cases like mappedBy, inversedBy, and joinTable for many-to-many relations.
     *
     * @param string|null $otherProp The name of the other property in the related entity, if applicable.
     * @param RelationKind|string $attr The relation kind or attribute name.
     * @param bool $isMany Whether this is a many-to-many or one-to-many relation.
     * @param JoinTable|null $joinTable The join table definition for many-to-many relations, if applicable.
     * @param bool $inverseO2O Whether this is an inverse one-to-one relation.
     * @param bool $isOwningSide Whether this is the owning side of the relation.
     * @return Node\Arg[] The array of arguments to be added to the attribute.
     */
    private function buildExtraArgs(
        ?string             $otherProp,
        RelationKind|string $attr,
        bool                $isMany,
        ?JoinTable          $joinTable,
        bool                $inverseO2O,
        bool                $isOwningSide = false
    ): array {
        $attrEnum = self::toEnum($attr);
        $args = [];
        if ($attrEnum === RelationKind::ONE_TO_ONE && $inverseO2O && $otherProp) {
            $args[] = new Node\Arg(
                new Node\Scalar\String_($otherProp),
                false,
                false,
                [],
                new Node\Identifier('mappedBy')
            );
        } elseif ($otherProp) {
            $args[] = new Node\Arg(
                new Node\Scalar\String_($otherProp),
                false,
                false,
                [],
                new Node\Identifier($isOwningSide ? 'inversedBy' : 'mappedBy')
            );
        }
        if ($attrEnum === RelationKind::MANY_TO_MANY && $isOwningSide && $joinTable) {
            $args[] = new Node\Arg(
                new Node\Expr\New_(
                    new Node\Name('JoinTable'),
                    [
                        new Node\Arg(new Node\Scalar\String_($joinTable->name), false, false, [], new Node\Identifier('name')),
                        new Node\Arg(new Node\Scalar\String_($joinTable->joinColumn), false, false, [], new Node\Identifier('joinColumn')),
                        new Node\Arg(new Node\Scalar\String_($joinTable->inverseColumn), false, false, [], new Node\Identifier('inverseColumn')),
                    ]
                ),
                false,
                false,
                [],
                new Node\Identifier('joinTable')
            );
        }
        return $args;
    }

    /**
     * Adds a collection initialization to the constructor for a property.
     * This ensures that collection properties (arrays) are initialized to an empty array
     * in the constructor if they are not already initialized.
     *
     * @param string $propName The name of the property to initialize.
     */
    private function addCollectionInitToConstructor(string $propName): void
    {
        $ctor = null;
        foreach ($this->classNode->stmts ?? [] as $stmt) {
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
        foreach ($ctor->stmts ?? [] as $s) {
            if (
                $s instanceof Node\Stmt\Expression &&
                $s->expr instanceof Node\Expr\Assign &&
                $s->expr->var instanceof Node\Expr\PropertyFetch &&
                $s->expr->var->var instanceof Node\Expr\Variable &&
                $s->expr->var->var->name === 'this' &&
                $s->expr->var->name instanceof Node\Identifier &&
                $s->expr->var->name->name === $propName
            ) {
                return;
            }
        }
        $ctor->stmts[] = new Node\Stmt\Expression(
            new Node\Expr\Assign(
                new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $propName),
                new Node\Expr\Array_([])
            )
        );
    }

    /**
     * Removes a property from the class by its name
     * This will filter out the property node from the class statements.
     * @param string $name The name of the property to remove.
     */
    private function removeProperty(string $name): void
    {
        $stmts = &$this->classNode->stmts;
        $stmts = array_values(array_filter($stmts, function ($stmt) use ($name) {
            return !($stmt instanceof Property && $stmt->props[0]->name->name === $name);
        }));
    }

    /**
     * Inserts a property node into the class at the appropriate position.
     * This handles blank lines before/after the property to ensure proper formatting.
     *
     * @param Property $prop The property node to insert.
     */
    private function insertProperty(Property $prop): void
    {
        $stmts = &$this->classNode->stmts;
        $insertIndex = $this->findPropertyInsertionPoint($stmts);

        // Remove blank lines immediately before/after the insertion point to avoid duplicates
        if ($insertIndex > 0 && $this->isBlankLine($stmts[$insertIndex - 1])) {
            array_splice($stmts, $insertIndex - 1, 1);
            $insertIndex--;
        }
        if (isset($stmts[$insertIndex]) && $this->isBlankLine($stmts[$insertIndex])) {
            array_splice($stmts, $insertIndex, 1);
        }

        // Always insert a blank line before the property except for the very first property (usually 'id')
        if ($insertIndex > 0) {
            if ($stmts[$insertIndex - 1] instanceof Property) {
                array_splice($stmts, $insertIndex, 0, [$this->newLineNode()]);
                $insertIndex++;
            }
        }

        array_splice($stmts, $insertIndex, 0, [$prop]);
        $insertIndex++;

        // Always insert a blank line after the property unless next is already blank or method
        if (!isset($stmts[$insertIndex]) || !$this->isBlankLine($stmts[$insertIndex])) {
            array_splice($stmts, $insertIndex, 0, [$this->newLineNode()]);
        }
    }

    /**
     * Returns a node that will be rendered as a blank line in the output.
     */
    private function newLineNode(): Node
    {
        return new Nop();
    }

    /**
     * Checks if a node is a blank line (Nop node).
     * This is used to determine if we need to insert or remove blank lines.
     *
     * @param Node $node The node to check.
     * @return bool True if the node is a blank line, false otherwise.
     */
    private function isBlankLine(Node $node): bool
    {
        return $node instanceof Nop;
    }

    /**
     * Inserts a method node into the class at the appropriate position.
     * This will always insert the method at the bottom of the class (after all other methods).
     *
     * @param ClassMethod $method The method node to insert.
     */
    private function insertMethod(ClassMethod $method): void
    {
        $stmts = &$this->classNode->stmts;
        $lastMethodIndex = -1;
        foreach ($stmts as $i => $stmt) {
            if ($stmt instanceof ClassMethod) {
                $lastMethodIndex = $i;
            }
        }
        $insertIndex = $lastMethodIndex >= 0 ? $lastMethodIndex + 1 : count($stmts);
        array_splice($stmts, $insertIndex, 0, [$method]);
    }

    /**
     * Finds the insertion point for a new property in the class statements.
     * This will return the index where the property should be inserted,
     * which is after the last property or before the first method.
     *
     * @param array<\PhpParser\Node\Stmt> $stmts The class statements to search.
     * @return int The index where the new property should be inserted.
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
     * Saves the modified class back to the original file.
     * This will pretty-print the AST and ensure it starts with <?php.
     * It also formats the code according to the defined rules.
     */
    public function save(): void
    {
        $code = $this->prettyPrinter->prettyPrint($this->ast);
        $code = $this->formatCode($code);

        // Ensure the code starts with <?php
        if (!str_starts_with($code, '<?php')) {
            $code = "<?php\n\n" . $code;
        }

        file_put_contents($this->file, $code);
    }

    /**
     * Formats the code according to the defined rules.
     * This includes fixing spacing in declare statements, ensuring blank lines,
     * and removing multiple consecutive blank lines.
     *
     * @param string $code The code to format.
     * @return string The formatted code.
     */
    private function formatCode(string $code): string
    {
        // Fix spacing in declare statement - remove space after 'declare'
        $code = preg_replace(
            '/declare\s+\(\s*strict_types\s*=\s*1\s*\)\s*;/',
            'declare(strict_types=1);',
            $code
        ) ?: '';

        // Ensure single blank line after declare(strict_types=1);
        $code = preg_replace(
            '/(declare\(strict_types=1\);\s*)\n+/',
            "$1\n\n",
            $code
        ) ?: '';

        // Fix spacing after use statements
        $code = preg_replace(
            '/(use\s+[^;]+;\s*\n)(?!\s*\n)(\s*(?:#\[|\bclass\b))/m',
            "$1\n$2",
            $code
        ) ?: '';

        // Add blank lines between ALL properties (not just Field attributes)
        $code = preg_replace(
            '/(#\[[^\]]*\]\s*\n\s*public\s+[^;]+;)\s*\n(\s*#\[)/m',
            "$1\n\n$2",
            $code
        ) ?: '';

        // Add blank line after properties before methods
        $code = preg_replace(
            '/(public\s+[^;]+;)\s*\n(\s*public\s+function)/m',
            "$1\n\n$2",
            $code
        ) ?: '';

        // Ensure single blank line between methods
        $code = preg_replace(
            '/(\}\s*\n)(\s*public\s+function)/m',
            "$1\n$2",
            $code
        ) ?: '';

        // Remove multiple consecutive blank lines
        $code = preg_replace('/\n{3,}/', "\n\n", $code) ?: '';

        return $code;
    }

    /**
     * Converts a RelationKind or string to a RelationKind enum.
     * This handles both enum instances and string representations of relation kinds.
     *
     * @param RelationKind|string $kind The relation kind to convert.
     * @return RelationKind The corresponding RelationKind enum.
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
}
