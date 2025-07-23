<?php

namespace MonkeysLegion\Cli\Service;

use PhpParser\ParserFactory;
use PhpParser\BuilderFactory;
use PhpParser\PrettyPrinter\Standard;
use PhpParser\NodeFinder;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node;
use MonkeysLegion\Entity\Attributes\JoinTable;
use PhpParser\Node\Stmt\Nop;

class ClassManipulator
{
    private $parser;
    private $builderFactory;
    private $prettyPrinter;
    private $nodeFinder;
    private $ast;
    private $classNode;
    private $file;
    private $oldStmts;
    private $oldTokens;

    public function __construct(string $file)
    {
        //TODO: Still needs refactoring and ensuring psr-12 compliance
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->builderFactory = new BuilderFactory();
        $this->prettyPrinter = new Standard();
        $this->nodeFinder = new NodeFinder();
        $this->file = $file;
        $src = file_exists($file) ? file_get_contents($file) : '';
        $this->ast = $src ? $this->parser->parse($src) : null;
        $this->classNode = $this->ast ? $this->nodeFinder->findFirstInstanceOf($this->ast, Class_::class) : null;
        // Store original statements and tokens for format-preserving printing
        $this->oldStmts = $this->ast;
        $this->oldTokens = $src ? $this->parser->getTokens() : [];
    }

    public function addScalarField(string $name, string $dbType, string $phpType)
    {
        $type = ltrim($phpType, '?');

        $attr = $this->builderFactory->attribute('Field', ['type' => $dbType]);
        $propBuilder = $this->builderFactory->property($name)
            ->makePublic()
            ->setType($type)
            ->addAttribute($attr);
        $prop = $propBuilder->getNode();

        $this->removeProperty($name);
        $this->insertProperty($prop);

        // Getter
        $getter = $this->builderFactory->method('get' . ucfirst($name))
            ->makePublic()
            ->setReturnType($type)
            ->addStmt(new Node\Stmt\Return_(
                new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $name)
            ))
            ->getNode();

        $this->insertMethod($getter);

        // Setter
        $setter = $this->builderFactory->method('set' . ucfirst($name))
            ->makePublic()
            ->setReturnType('self')
            ->addParam($this->builderFactory->param($name)->setType($type))
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
        // Only allow valid class names for targetEntity
        if (!preg_match('/^[A-Z][A-Za-z0-9_]*$/', $targetShort)) {
            return;
        }

        $args = [
            new Node\Arg(new Node\Expr\ClassConstFetch(
                new Node\Name($targetShort),
                'class'
            ), false, false, [], new Node\Identifier('targetEntity'))
        ];
        if ($attr === 'OneToOne' && $inverseO2O && $otherProp) {
            $args[] = new Node\Arg(new Node\Scalar\String_($otherProp), false, false, [], new Node\Identifier('mappedBy'));
        } elseif ($otherProp) {
            $args[] = new Node\Arg(
                new Node\Scalar\String_($otherProp),
                false,
                false,
                [],
                new Node\Identifier(in_array($attr, ['OneToMany', 'ManyToMany']) ? 'mappedBy' : 'inversedBy')
            );
        }
        if ($attr === 'ManyToMany' && $joinTable) {
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
        // Ensure attribute name is correct case
        $attrNode = $this->builderFactory->attribute(ucfirst($attr), $args);

        // Property
        $type = $isMany ? 'array' : '?' . $targetShort;
        $propBuilder = $this->builderFactory->property($name)
            ->makePublic()
            ->addAttribute($attrNode)
            ->setType($type);

        if ($isMany) {
            $propBuilder->setDefault([]);
        }
        $prop = $propBuilder->getNode();

        // Remove any existing property with same name
        $this->removeProperty($name);
        $this->insertProperty($prop);
        $this->insertStatementBeforeFirstMethod(new Nop()); // Blank line after

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
                        new Node\Arg(
                            new Node\Expr\Closure([
                                'params' => [new Node\Param(new Node\Expr\Variable('i')), new Node\Param(new Node\Expr\Variable('item'))],
                                'stmts' => [
                                    new Node\Stmt\Return_(
                                        new Node\Expr\BinaryOp\NotIdentical(
                                            new Node\Expr\Variable('i'),
                                            new Node\Expr\Variable('item')
                                        )
                                    )
                                ],
                                'static' => false,
                                'uses' => [new Node\Expr\Variable('item')]
                            ])
                        )
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

    private function removeProperty(string $name)
    {
        $stmts = &$this->classNode->stmts;
        $stmts = array_filter($stmts, function ($stmt) use ($name) {
            return !($stmt instanceof Property && $stmt->props[0]->name->name === $name);
        });
    }

    private function insertProperty(Property $prop)
    {
        $this->insertStatementBeforeFirstMethod($prop);
    }

    private function ensureSingleBlankLineBetweenMethods()
    {
        $stmts = &$this->classNode->stmts;
        $newStmts = [];
        $prevWasMethod = false;
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Nop) {
                // Only insert blank line if previous was a method and next is a method
                continue;
            }
            if ($prevWasMethod && $stmt instanceof ClassMethod) {
                $newStmts[] = new Node\Stmt\Nop();
            }
            $newStmts[] = $stmt;
            $prevWasMethod = $stmt instanceof ClassMethod;
        }
        $stmts = $newStmts;
    }

    private function insertMethod(ClassMethod $method)
    {
        $stmts = &$this->classNode->stmts;
        $idx = count($stmts);
        $hasCtor = false;
        foreach ($stmts as $i => $stmt) {
            if ($stmt instanceof ClassMethod && $stmt->name->toString() === '__construct') {
                $idx = $i + 1;
                $hasCtor = true;
                break;
            }
        }
        // If no constructor, fallback to after last property
        if (!$hasCtor) {
            foreach ($stmts as $i => $stmt) {
                if ($stmt instanceof Property) {
                    $idx = $i + 1;
                }
            }
        }

        array_splice($stmts, $idx, 0, [$method]);
        $this->ensureSingleBlankLineBetweenMethods();
    }

    public function save()
    {
        $this->ensureSingleBlankLineBetweenMethods();
        $code = $this->prettyPrinter->printFormatPreserving(
            $this->ast,
            $this->oldStmts,
            $this->oldTokens
        );

        file_put_contents($this->file, $code);
    }

    private function insertStatementBeforeFirstMethod(Node $stmt)
    {
        $stmts = &$this->classNode->stmts;
        $idx = 0;
        foreach ($stmts as $i => $node) {
            if ($node instanceof ClassMethod) {
                $idx = $i;
                break;
            }
            if ($node instanceof Property) {
                $idx = $i + 1;
            }
        }
        array_splice($stmts, $idx, 0, [$stmt]);
    }
}
