<?php

namespace MonkeysLegion\Cli\Service;

use MonkeysLegion\Cli\Config\RelationKind;
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

class ClassManipulator
{
    private $parser;
    private $builderFactory;
    private $prettyPrinter;
    private $nodeFinder;
    private $ast;
    private $classNode;
    private $file;
    private array $owningShouldBePlural = [
        RelationKind::MANY_TO_ONE->value,
        RelationKind::MANY_TO_MANY->value,
    ];

    public function __construct(string $file)
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->builderFactory = new BuilderFactory();
        $this->prettyPrinter = new Standard();
        $this->nodeFinder = new NodeFinder();
        $this->file = $file;

        $src = file_exists($file) ? file_get_contents($file) : '';
        $this->ast = $src ? $this->parser->parse($src) : null;
        $this->classNode = $this->ast ? $this->nodeFinder->findFirstInstanceOf($this->ast, Class_::class) : null;
    }

    public function addScalarField(string $name, string $dbType, string $phpType)
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

    public function addRelation(
        string $name,
        string $attr,
        string $targetShort,
        bool $isMany,
        ?string $otherProp = null,
        ?JoinTable $joinTable = null,
        bool $inverseO2O = false
    ) {
        if (!preg_match('/^[A-Z][A-Za-z0-9_]*$/', $targetShort)) {
            return;
        }

        // Build extra attribute arguments
        $extra = '';
        if ($attr === RelationKind::ONE_TO_ONE->value && $inverseO2O && $otherProp) {
            $extra .= ", mappedBy: '{$otherProp}'";
        } elseif ($otherProp) {
            $extra .= in_array($attr, $this->owningShouldBePlural, true)
                ? ", mappedBy: '{$otherProp}'"
                : ", inversedBy: '{$otherProp}'";
        }
        if ($attr === RelationKind::MANY_TO_MANY->value && $joinTable) {
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
            $docComment = "/** @var {$targetShort}[] */";
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
                        ...$this->buildExtraArgs($otherProp, $attr, $isMany, $joinTable, $inverseO2O)
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
                        ...$this->buildExtraArgs($otherProp, $attr, $isMany, $joinTable, $inverseO2O)
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
                ->setReturnType($targetShort)
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
     * Helper to build extra named arguments for relation attributes.
     */
    private function buildExtraArgs($otherProp, $attr, $isMany, $joinTable, $inverseO2O)
    {
        $args = [];
        if ($attr === RelationKind::ONE_TO_ONE->value && $inverseO2O && $otherProp) {
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
                new Node\Identifier(
                    in_array($attr, $this->owningShouldBePlural, true)
                        ? 'mappedBy'
                        : 'inversedBy'
                )
            );
        }
        if ($attr === RelationKind::MANY_TO_MANY->value && $joinTable) {
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
     * Adds initialization for a collection property in the constructor, without overwriting or duplicating.
     */
    private function addCollectionInitToConstructor(string $propName)
    {
        $ctor = null;
        foreach ($this->classNode->stmts as $stmt) {
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
        // Check if already initialized
        foreach ($ctor->stmts as $s) {
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
            // Also handle direct assignment (not wrapped in Expression)
            if (
                $s instanceof Node\Expr\Assign &&
                $s->var instanceof Node\Expr\PropertyFetch &&
                $s->var->var instanceof Node\Expr\Variable &&
                $s->var->var->name === 'this' &&
                $s->var->name instanceof Node\Identifier &&
                $s->var->name->name === $propName
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

    private function removeProperty(string $name)
    {
        $stmts = &$this->classNode->stmts;
        $stmts = array_values(array_filter($stmts, function ($stmt) use ($name) {
            return !($stmt instanceof Property && $stmt->props[0]->name->name === $name);
        }));
    }

    private function insertProperty(Property $prop)
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
        return new \PhpParser\Node\Stmt\Nop();
    }

    /**
     * Checks if a node is a blank line (Nop).
     */
    private function isBlankLine($node): bool
    {
        return $node instanceof \PhpParser\Node\Stmt\Nop;
    }

    private function insertMethod(ClassMethod $method)
    {
        $stmts = &$this->classNode->stmts;
        $insertIndex = $this->findMethodInsertionPoint($stmts);
        array_splice($stmts, $insertIndex, 0, [$method]);
    }

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

    private function findMethodInsertionPoint(array $stmts): int
    {
        // Insert after constructor if it exists, otherwise after properties
        foreach ($stmts as $i => $stmt) {
            if ($stmt instanceof ClassMethod && $stmt->name->toString() === '__construct') {
                return $i + 1;
            }
        }

        // No constructor, insert after last property
        return $this->findPropertyInsertionPoint($stmts);
    }

    public function save()
    {
        $code = $this->prettyPrinter->prettyPrint($this->ast);
        $code = $this->formatCode($code);

        // Ensure the code starts with <?php
        if (!str_starts_with($code, '<?php')) {
            $code = "<?php\n\n" . $code;
        }

        file_put_contents($this->file, $code);
    }

    private function formatCode(string $code): string
    {
        // Fix spacing in declare statement - remove space after 'declare'
        $code = preg_replace(
            '/declare\s+\(\s*strict_types\s*=\s*1\s*\)\s*;/',
            'declare(strict_types=1);',
            $code
        );

        // Ensure single blank line after declare(strict_types=1);
        $code = preg_replace(
            '/(declare\(strict_types=1\);\s*)\n+/',
            "$1\n\n",
            $code
        );

        // Fix spacing after use statements
        $code = preg_replace(
            '/(use\s+[^;]+;\s*\n)(?!\s*\n)(\s*(?:#\[|\bclass\b))/m',
            "$1\n$2",
            $code
        );

        // Add blank lines between ALL properties (not just Field attributes)
        $code = preg_replace(
            '/(#\[[^\]]*\]\s*\n\s*public\s+[^;]+;)\s*\n(\s*#\[)/m',
            "$1\n\n$2",
            $code
        );

        // Add blank line after properties before methods
        $code = preg_replace(
            '/(public\s+[^;]+;)\s*\n(\s*public\s+function)/m',
            "$1\n\n$2",
            $code
        );

        // Ensure single blank line between methods
        $code = preg_replace(
            '/(\}\s*\n)(\s*public\s+function)/m',
            "$1\n$2",
            $code
        );

        // Remove multiple consecutive blank lines
        $code = preg_replace('/\n{3,}/', "\n\n", $code);

        return $code;
    }
}
