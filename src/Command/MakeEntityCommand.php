<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Config\EntityConfig;
use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Entity\Attributes\JoinTable;
use Doctrine\Inflector\Inflector;
use Doctrine\Inflector\InflectorFactory;
use MonkeysLegion\Cli\Config\FieldType;
use MonkeysLegion\Cli\Config\RelationKind;
use MonkeysLegion\Cli\Helpers\Identifier;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PhpParser\BuilderFactory;
use PhpParser\Modifiers;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\PrettyPrinter;
use MonkeysLegion\Cli\Service\ClassManipulator;

enum CompletionContext: string
{
    case ENTITY = 'entity';
    case FIELD = 'field';
    case RELATION = 'relation';
    case DEFAULT = 'default';
}

#[CommandAttr('make:entity', 'Generate or update an Entity class with fields & relationships')]
final class MakeEntityCommand extends Command
{

    /** @var string[] DB scalar types offered in the wizard */
    private array $fieldTypes;

    private object $relTypes;

    /** 
     * Maps each owning-side relation kind (e.g., ONE_TO_MANY) 
     * to its corresponding inverse relation kind (e.g., MANY_TO_ONE). 
     */
    private object $inverseMap;

    /** 
     * Maps DB field types (e.g., "string", "json") 
     * to their corresponding PHP native types (e.g., "string", "array"). 
     */
    private object $phpTypeMap;

    /** @var array<string, string> Field names in the current entity */
    protected array $fieldNames = [];

    /** @var array<string, string> Relationship names in the current entity */
    protected array $relNames = [];

    public function __construct(
        private EntityConfig $config,
    ) {
        parent::__construct();

        $this->fieldTypes = $this->config->fieldTypes->all();
        $this->fieldTypes = array_map(fn(FieldType $case) => $case->value, $this->fieldTypes);

        $this->relTypes = $this->config->relationKeywordMap;;

        $this->inverseMap = $this->config->relationInverseMap;

        $this->phpTypeMap = $this->config->phpTypeMap;
    }

    /* helpers */
    private string $completionContext = CompletionContext::DEFAULT->value;
    private array $contextAwareCompletions = [];
    private array $inverseQueue = [];

    /** @var Inflector */
    private Inflector $inflector;

    /**
     * Handles the process of creating or updating an entity file.
     *
     * This method interacts with the user to define an entity name, ensures the file exists,
     * and provides a wizard interface for adding fields and relationships to the entity.
     * It also generates the necessary file fragments and injects them into the entity file,
     * applying updates or creating a new file as needed.
     *
     * @return int Status code indicating success or failure.
     */
    protected function handle(): int
    {
        if (function_exists('readline_completion_function')) {
            readline_completion_function([$this, 'contextAwareCompleter']);
        }

        $this->inflector = InflectorFactory::create()->build();
        $dir  = base_path('app/Entity');
        @mkdir($dir, 0755, true);

        /* 0️⃣  prepare entities name */
        $entityFiles = glob($dir . '/*.php');
        $entities = [];
        foreach ($entityFiles as $filePath) {
            // Get filename without extension, e.g. User.php -> User
            $fileName = basename($filePath, '.php');
            $entities[$fileName] = $fileName;
        }
        $this->setCompletionContext(CompletionContext::ENTITY, array_keys($entities));

        /* 1️⃣  entity name */
        $name = $_SERVER['argv'][2] ?? $this->ask('Enter entity name (e.g. User)');
        if (!preg_match('/^[A-Z][A-Za-z0-9]+$/', $name)) {
            return $this->fail('Invalid class name – must start with uppercase.');
        }
        if (!isset($entities[$name])) {
            $entities[$name] = $name;
            $this->setCompletionContext(CompletionContext::FIELD, array_keys($entities));
        }

        /* 2️⃣  ensure file exists */
        $file = "$dir/$name.php";
        if (!is_file($file)) {
            $this->createStub($name, $file);
            $this->info("✅  Created stub $file");
            $this->createRepoStub($name);
        }

        // Use ClassManipulator for AST-based editing
        $manipulator = new ClassManipulator($file);

        /* 3️⃣  scan existing props */
        $src = file_get_contents($file);
        $ast = (new \PhpParser\ParserFactory())->createForNewestSupportedVersion()->parse($src);
        $existingFields = [];
        $existingRels = [];
        if ($ast) {
            $class = (new \PhpParser\NodeFinder())->findFirstInstanceOf($ast, \PhpParser\Node\Stmt\Class_::class);
            if ($class) {
                foreach ($class->stmts as $stmt) {
                    if ($stmt instanceof \PhpParser\Node\Stmt\Property) {
                        $name = $stmt->props[0]->name->name;
                        foreach ($stmt->attrGroups as $attrGroup) {
                            foreach ($attrGroup->attrs as $attr) {
                                $attrName = $attr->name->toString();
                                if ($attrName === 'Field') {
                                    $existingFields[] = $name;
                                }
                                if (in_array($attrName, array_values($this->relTypes->all()), true)) {
                                    $existingRels[] = $name;
                                }
                            }
                        }
                    }
                }
            }
        }
        $existingFields = array_fill_keys($existingFields, []);
        $existingRels = array_fill_keys($existingRels, []);

        /* 5️⃣  wizard */
        menu:
        $this->info("\n===== Make Entity: $name =====");
        $this->line("[1] Add field");
        $this->line("[2] Add relationship");
        $this->line("[3] Finish & save");
        switch ($this->ask('Choose option 1-3')) {
            case '1':
                $this->wizardField($existingFields);
                goto menu;
            case '2':
                $this->wizardRelation($existingRels, $name);
                goto menu;
            case '3':
                break;
            default:
                $this->error('Enter 1, 2 or 3');
                goto menu;
        }
        if (!$this->fieldNames && !$this->relNames) {
            $this->info('No changes.');
            return self::SUCCESS;
        }

        /* 6️⃣  build fragments and inject via ClassManipulator */
        foreach ($this->fieldNames as $p => $t) {
            $fieldType = FieldType::tryFrom($t);
            if (!$fieldType) {
                $this->error("Unknown field type '$t' for property '$p'.");
                continue;
            }
            $phpType = $this->phpTypeMap->map($fieldType);
            $manipulator->addScalarField($p, $t, $phpType);
        }
        foreach ($this->relNames as $p => $m) {
            $short = substr($m['target'], strrpos($m['target'], '\\') + 1);
            $isMany = in_array($m['attr'], [$this->getRelAtt(RelationKind::ONE_TO_MANY), $this->getRelAtt(RelationKind::MANY_TO_MANY)], true);
            $manipulator->addRelation(
                $p,
                $m['attr'],
                $short,
                $isMany,
                $m['other_prop'] ?? null,
                $m['joinTable'] ?? null,
                $m['attr'] === 'OneToOne' && !empty($m['other_prop'])
            );
        }
        $manipulator->save();
        $this->info("✅  Updated $file");

        /* 8️⃣  inverse patch */
        $this->applyInverseQueue();
        return self::SUCCESS;
    }

    private function getRelAtt(RelationKind $kind): string
    {
        return $this->relTypes->getAttribute($kind) ?? $kind->value;
    }

    /**
     * Prompt the user for a field name and type.
     *
     * @param array $existing Existing properties to check against.
     */
    private function wizardField(array $existing): void
    {
        $this->setCompletionContext(CompletionContext::DEFAULT, []);
        $prop = $this->ask('  Field name: ');

        if (!Identifier::isValid($prop)) {
            $this->error('Invalid.');
            return;
        }
        if (isset($existing[$prop])) {
            $this->error("Field '$prop' already exists in the class.");
            return;
        }
        if (isset($this->fieldNames[$prop])) {
            $this->error("Field '$prop' already added during this wizard session.");
            return;
        }

        $this->setCompletionContext(CompletionContext::FIELD, $this->fieldTypes);
        $type = $this->chooseOption('field', $this->fieldTypes);
        $this->fieldNames[$prop] = $type;
        $this->info("  ➕  $prop:$type added.");
    }

    /**
     * Prompt the user for a relationship type and target entity.
     *
     * @param array $existing Existing properties to check against.
     * @param array $out      Output array to store the new property.
     * @param string $selfClass The name of the current class.
     */
    private function wizardRelation(
        array $existing,
        string $selfClass
    ): void {
        $opts = $this->relTypes->all();
        $this->setCompletionContext(CompletionContext::RELATION, array_keys($opts));
        $kind = $this->chooseOption('relation', array_keys($opts));
        $relCase = $this->relTypes->tryFrom($kind);
        $attr = $this->relTypes->getAttribute($relCase);

        /* target entity */
        $entities = array_map(
            fn($f) => basename($f, '.php'),
            glob(base_path('app/Entity') . '/*.php')
        );
        $this->setCompletionContext(CompletionContext::ENTITY, $entities);
        $target = $this->ask('  Target entity');
        if (!Identifier::isValid($target)) {
            $this->error('Invalid entity name.');
            return;
        }

        $short = str_contains($target, '\\') ? substr($target, strrpos($target, '\\') + 1) : $target;
        $fqcn  = str_contains($target, '\\') ? $target : "App\\Entity\\$target";

        if (in_array($attr, [$this->getRelAtt(RelationKind::ONE_TO_MANY), $this->getRelAtt(RelationKind::MANY_TO_MANY)], true)) {
            // plural suggestion for collections
            $suggest = lcfirst($this->inflector->pluralize($short));
        } else {
            // singular for 1-to-1 / many-to-1
            $suggest = lcfirst($short);
        }

        $joinTable = null;
        /* property name */
        if ($attr === 'ManyToMany') {
            // default to owning-sided ManyToMany
            $owning = true;
            if ($owning) {
                // default table name: alphabetical snake
                [$a, $b] = [lcfirst($selfClass), lcfirst($short)];
                // build array, then sort it by reference
                $arr = [$this->snake($a), $this->snake($b)];
                sort($arr);
                $default = implode('_', $arr);

                $tbl  = $this->ask("  Join table name [$default]") ?: $default;
                $colA = $this->ask("  Column for {$selfClass} [{$arr[0]}_id]")  ?: "{$arr[0]}_id";
                $colB = $this->ask("  Column for {$short} [{$arr[1]}_id]")      ?: "{$arr[1]}_id";
                $joinTable = new JoinTable(name: $tbl, joinColumn: $colA, inverseColumn: $colB);
            }
        }
        $prop = $this->ask("  Property name [$suggest]") ?: $suggest;
        if ($prop === '' || isset($existing[$prop]) || isset($this->relNames[$prop])) return;

        /* inverse side? */
        $inverseProp = null;
        if (strtolower($this->ask('  Generate inverse side in target? [y/N]')) === 'y') {
            $invRelationKind = $this->inverseMap->getInverse($relCase);
            $invAttr = $invRelationKind->value;
            $base = lcfirst($selfClass);
            if (in_array($invAttr, [$this->getRelAtt(RelationKind::ONE_TO_MANY), $this->getRelAtt(RelationKind::MANY_TO_MANY)], true)) {
                // proper plural, e.g. “companies”
                $defName = $this->inflector->pluralize($base);
            } else {
                // singular
                $defName = $base;
            }

            $inverseProp = $this->ask("  Inverse property in $short [{$defName}]") ?: $defName;
            $this->queueInverse(
                $fqcn,
                $inverseProp,
                $invAttr,
                "App\\Entity\\$selfClass",
                $prop,
                $attr === 'OneToOne',
            );
        }

        $this->relNames[$prop] = [
            'attr'       => $attr,
            'target'     => $fqcn,
            'other_prop' => $inverseProp,
            'joinTable'  => $joinTable
        ];

        $this->info("  ➕  $prop:$kind ➔ $fqcn");
    }

    /* ───────────── Fragment emitters ───────────── */

    /**
     * Emit the fragments for a scalar field:
     *  - the #[Field] attribute
     *  - the private property
     *  - the getter and setter methods
     *
     * @param string   $prop   The property name (e.g. “name”)
     * @param string   $db     The DB type keyword (e.g. “string”, “json”)
     * @param string[] &$props Accumulator for property‐definition lines
     * @param string[] &$meth  Accumulator for method‐definition lines
     */
    private function emitField(
        string $prop,
        string $db,
        array  &$props,
        array  &$meth
    ): void {
        $fieldType = FieldType::tryFrom($db);
        if (!$fieldType) {
            $this->error("Unknown field type '$db'.");
            return;
        }

        $type = $this->phpTypeMap->map($fieldType);
        $Stud  = ucfirst($prop);

        // ─────────── property & attribute ───────────
        $props[] = "    #[Field(type: '{$db}')]";
        $props[] = "    public {$type} \${$prop};";
        $props[] = "";

        // ─────────── getter ───────────
        $meth[]  = "    public function get{$Stud}(): {$type}";
        $meth[]  = "    {";
        $meth[]  = "        return \$this->{$prop};";
        $meth[]  = "    }";
        $meth[]  = "";

        // ─────────── setter ───────────
        $meth[]  = "    public function set{$Stud}({$type} \${$prop}): self";
        $meth[]  = "    {";
        $meth[]  = "        \$this->{$prop} = \${$prop};";
        $meth[]  = "        return \$this;";
        $meth[]  = "    }";
        $meth[]  = "";
    }

    /**
     * Emit relation fragments.
     *
     * @param string      $prop       the property name
     * @param string      $attr       the relation attribute (OneToOne, OneToMany…)
     * @param string      $target     the FQCN of the target entity
     * @param array       &$props     where to append the generated property lines
     * @param array       &$ctor      where to append any constructor initializers
     * @param array       &$meth      where to append the generated methods
     * @param string|null $otherProp  property name on the opposite side (null if none)
     * @param JoinTable|null $joinTable  the join table definition (if ManyToMany)
     * @param bool       $skipProperty whether to skip emitting the property itself
     */
    private function emitRelation(
        string $prop,
        string $attr,
        string $target,
        array  &$props,
        array  &$ctor,
        array  &$meth,
        ?string $otherProp = null,
        ?JoinTable $joinTable = null,
        bool $skipProperty = false,
        bool $inverseO2O = false
    ): void {
        // short class name (“Project” from “App\Entity\Project”)
        $short = substr($target, strrpos($target, '\\') + 1);
        $Stud  = ucfirst($prop);
        $many  = in_array($attr, [$this->getRelAtt(RelationKind::ONE_TO_MANY), $this->getRelAtt(RelationKind::MANY_TO_MANY)], true);

        /* ───── build attribute arguments ───── */
        $args = ["targetEntity: {$short}::class"];

        /* ①  special-case: inverse side of One-to-One  */
        if ($attr === 'OneToOne' && $inverseO2O && $otherProp) {
            // explicit inverse side ⇒ mappedBy
            $args[] = "mappedBy: '{$otherProp}'";
        } elseif ($otherProp) {
            $args[] = in_array($attr, [$this->getRelAtt(RelationKind::ONE_TO_MANY), $this->getRelAtt(RelationKind::MANY_TO_MANY)], true)
                ? "mappedBy: '{$otherProp}'"
                : "inversedBy: '{$otherProp}'";
        }

        /* ③  joinTable for owning Many-to-Many (unchanged) */
        if ($attr === 'ManyToMany' && $joinTable) {
            $jt = $joinTable;
            $args[] = "joinTable: new JoinTable(name: '{$jt->name}', "
                . "joinColumn: '{$jt->joinColumn}', "
                . "inverseColumn: '{$jt->inverseColumn}')";
        }

        /* ───── property + constructor (only if not skipped) ───── */
        if (!$skipProperty) {
            if ($many) {
                $props[] = "    /** @var {$short}[] */";
            }
            $props[] = '    #[' . $attr . '(' . implode(', ', $args) . ')]';
            $props[] = $many
                ? "    public array \${$prop};"
                : "    public ?{$short} \${$prop} = null;";
            $props[] = "";

            if ($many) {            // initialise collection
                $ctor[] = "        \$this->{$prop} = [];";
            }
        }

        /* ───── methods (always emitted – duplicates filtered earlier) ───── */
        if ($many) {
            // add()
            $meth[] = "    public function add{$short}({$short} \$item): self";
            $meth[] = "    {";
            $meth[] = "        \$this->{$prop}[] = \$item;";
            $meth[] = "        return \$this;";
            $meth[] = "    }";
            $meth[] = "";

            // remove()
            $meth[] = "    public function remove{$short}({$short} \$item): self";
            $meth[] = "    {";
            $meth[] = "        \$this->{$prop} = array_filter(";
            $meth[] = "            \$this->{$prop}, fn(\$i) => \$i !== \$item";
            $meth[] = "        );";
            $meth[] = "        return \$this;";
            $meth[] = "    }";
            $meth[] = "";

            // getter()
            $meth[] = "    /** @return {$short}[] */";
            $meth[] = "    public function get{$Stud}(): array";
            $meth[] = "    {";
            $meth[] = "        return \$this->{$prop};";
            $meth[] = "    }";
            $meth[] = "";
        } else {
            // getter()
            $meth[] = "    public function get{$Stud}(): ?{$short}";
            $meth[] = "    {";
            $meth[] = "        return \$this->{$prop};";
            $meth[] = "    }";
            $meth[] = "";

            // setter()
            $meth[] = "    public function set{$Stud}(?{$short} \${$prop}): self";
            $meth[] = "    {";
            $meth[] = "        \$this->{$prop} = \${$prop};";
            $meth[] = "        return \$this;";
            $meth[] = "    }";
            $meth[] = "";

            // unset/remove()
            $meth[] = "    public function remove{$Stud}(): self";
            $meth[] = "    {";
            $meth[] = "        \$this->{$prop} = null;";
            $meth[] = "        return \$this;";
            $meth[] = "    }";
            $meth[] = "";
        }
    }

    /**
     * Queue up an inverse‐side relation to be applied after our own file is written.
     *
     * @param string      $fqcn       Fully qualified class name of the target entity
     * @param string      $prop       Property name on the target side
     * @param string      $attr       Relation attribute on the target (OneToMany, etc.)
     * @param string      $target     FQCN pointing back at our entity
     * @param string|null $otherProp  Property name on this side for mappedBy/inversedBy
     */
    private function queueInverse(
        string  $fqcn,
        string  $prop,
        string  $attr,
        string  $target,
        ?string $otherProp = null,
        bool    $isInverseOneToOne = false
    ): void {
        $this->inverseQueue[$fqcn][] = [
            'prop'       => $prop,
            'attr'       => $attr,
            'target'     => $target,
            'other_prop' => $otherProp,
            'inverse_o2o' => $isInverseOneToOne,
        ];
    }

    /**
     * Apply the queued inverse relations to the target entity files.
     *
     * @return void
     */
    private function applyInverseQueue(): void
    {
        foreach ($this->inverseQueue as $fqcn => $defs) {
            $file = base_path('app/Entity/' . substr($fqcn, strrpos($fqcn, '\\') + 1) . '.php');
            if (!is_file($file)) {
                $this->createStub(substr($fqcn, strrpos($fqcn, '\\') + 1), $file);
            }
            $manipulator = new \MonkeysLegion\Cli\Service\ClassManipulator($file);
            foreach ($defs as $d) {
                $short = substr($d['target'], strrpos($d['target'], '\\') + 1);
                $isMany = in_array($d['attr'], [$this->getRelAtt(RelationKind::ONE_TO_MANY), $this->getRelAtt(RelationKind::MANY_TO_MANY)], true);
                $manipulator->addRelation(
                    $d['prop'],
                    $d['attr'],
                    $short,
                    $isMany,
                    $d['other_prop'] ?? null,
                    $d['joinTable'] ?? null,
                    $d['inverse_o2o'] ?? false
                );
            }
            $manipulator->save();
            $this->info("    ↪  Patched inverse side in $file");
        }
    }


    /**
     * Prompts the user with a question and retrieves their input.
     *
     * @param string $q The question to prompt the user with.
     * @return string The user's input after trimming whitespace.
     */
    private function ask(string $q): string
    {
        return function_exists('readline') ? trim(readline("$q ")) : trim(fgets(STDIN));
    }

    /**
     * Displays a message to the user.
     *
     * @param string $in
     * @param int $i
     * @return array
     */
    public function readlineComplete(string $in, int $i): array
    {
        $opts = array_filter($this->contextAwareCompletions[$this->completionContext] ?? [], fn($o) => $this->fuzzyMatch($in, $o));
        $count = count($opts);

        if ($count > 0) {
            usort($opts, function ($a, $b) use ($in) {
                similar_text($in, $b, $percentB);
                similar_text($in, $a, $percentA);
                return $percentB <=> $percentA;
            });

            echo PHP_EOL . "Suggestions: " . implode(" | ", $opts) . PHP_EOL;
            echo "> " . $in;
        }

        return $count >= 1 ? [$in, ...$opts] : [$in];
    }

    /**
     * Single completer that adapts based on current context
     */
    public function contextAwareCompleter(string $input, int $index): array
    {
        switch ($this->completionContext) {
            case CompletionContext::ENTITY->value:
                return $this->completeEntityName($input, $index);
            case CompletionContext::FIELD->value:
                return $this->readlineComplete($input, $index);
            case CompletionContext::RELATION->value:
                return $this->readlineComplete($input, $index);
            default:
                return [];
        }
    }

    private function setCompletionContext(CompletionContext $context, array $completions = []): void
    {
        $this->completionContext = $context->value;
        $this->contextAwareCompletions[$context->value] = $completions;
    }

    /**
     * Completes entity names based on the input.
     *
     * This method provides suggestions for entity names based on the input string.
     * It uses various inflection methods to generate possible variants of the input.
     *
     * @param string $in The input string to complete.
     * @param int $i The index of the input (not used here).
     * @return array An array of suggestions including the original input.
     */
    public function completeEntityName(string $in, int $i): array
    {
        $returnOpts = $this->contextAwareCompletions[CompletionContext::ENTITY->value] ?? [];
        if (empty($returnOpts)) {
            return [$in];
        }
        if (empty($in)) {
            echo PHP_EOL . "Suggestions: " . implode(" | ", $returnOpts) . PHP_EOL;
            echo "> " . $in;
            return $returnOpts;
        }

        $inputVariants = [
            $in,
            $this->inflector->tableize($in),
            $this->inflector->classify($in),
            $this->inflector->pluralize($in),
            $this->inflector->pluralize($this->inflector->tableize($in)),
            $this->inflector->classify($this->inflector->pluralize($in)),
        ];

        $opts = array_filter($returnOpts, function ($entity) use ($inputVariants) {
            $normalizedEntity = strtolower($this->inflector->tableize($entity));
            foreach ($inputVariants as $variant) {
                if ($variant === null) continue;
                $variantNorm = strtolower($variant);
                if (strpos($normalizedEntity, $variantNorm) !== false) {
                    return true;
                }
            }
            return false;
        });

        $count = count($opts);

        if ($count > 0) {
            usort($opts, function ($a, $b) use ($in) {
                similar_text($in, $b, $percentB);
                similar_text($in, $a, $percentA);
                return $percentB <=> $percentA;
            });

            echo PHP_EOL . "Suggestions: " . implode(" | ", $opts) . PHP_EOL;
            echo "> " . $in;
        }

        return $count >= 1 ? [$in, ...$opts] : [$in];
    }

    /**
     * Checks if the input string matches any of the options in a fuzzy manner.
     *
     * @param string $input The input string to match against options.
     * @param string $option The option to check against.
     * @return bool True if the input matches the option, false otherwise.
     */
    private function fuzzyMatch(string $input, string $option): bool
    {
        return stripos($option, $input) !== false;
    }

    /**
     * Displays an error message to the user.
     *
     * @param string $kind
     * @param array $opts
     * @return string
     */
    private function chooseOption(string $kind, array $opts): string
    {
        foreach ($opts as $i => $o) $this->line(sprintf("  [%2d] %s", $i + 1, $o));
        while (true) {
            $sel = $this->ask("Select $kind");
            if (ctype_digit($sel) && isset($opts[$sel - 1])) return $opts[$sel - 1];
            if (in_array($sel, $opts, true)) return $sel;
            $this->error('  Invalid choice.');
        }
    }

    /**
     * Create a stub file for a new entity.
     *
     * This method generates a basic PHP class file with the necessary attributes
     * and a constructor, as well as a getter for the ID field.
     *
     * @param string $name The name of the entity class.
     * @param string $file The path where the stub file should be created.
     */
    private function createStub(string $name, string $file): void
    {
        $template = <<<PHP
                    <?php
                    declare(strict_types=1);

                    namespace App\Entity;

                    use MonkeysLegion\Entity\Attributes\Entity;
                    use MonkeysLegion\Entity\Attributes\Field;
                    use MonkeysLegion\Entity\Attributes\OneToOne;
                    use MonkeysLegion\Entity\Attributes\OneToMany;
                    use MonkeysLegion\Entity\Attributes\ManyToOne;
                    use MonkeysLegion\Entity\Attributes\ManyToMany;
                    use MonkeysLegion\Entity\Attributes\JoinTable;

                    #[Entity]
                    class {$name}
                    {
                        #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
                        public int \$id;

                        public function __construct()
                        {
                        }

                        public function getId(): int
                        {
                            return \$this->id;
                        }
                    }

                    PHP;

        file_put_contents($file, $template);
    }

    /**
     * Create a stub file for the entity.
     *
     * @param string $msg
     * @return int
     */
    private function fail(string $msg): int
    {
        $this->error($msg);
        return self::FAILURE;
    }

    /**
     * Create a repository stub file for a given entity.
     *
     * @param string $entity The name of the entity for which the repository stub should be created.
     *
     * @return void
     */
    private function createRepoStub(string $entity): void
    {
        $dir  = base_path('app/Repository');
        $file = "$dir/{$entity}Repository.php";
        @mkdir($dir, 0755, true);

        if (is_file($file)) {
            $this->line("ℹ️  Repository already exists: $file");
            return;
        }

        $table = $this->snake($entity);

        $code = <<<PHP
                <?php
                declare(strict_types=1);

                namespace App\Repository;

                use MonkeysLegion\Repository\EntityRepository;
                use App\Entity\\{$entity};

                /**
                 * @extends EntityRepository<{$entity}>
                 */
                class {$entity}Repository extends EntityRepository
                {
                    protected string \$table       = '$table';
                    protected string \$entityClass = {$entity}::class;

                    /**
                     * Shortcut that keeps return type specific to {$entity}.
                     *
                     * @param array<string,mixed> \$criteria
                     * @return {$entity}[]
                     */
                    public function findAll(array \$criteria = []): array
                    {
                        /** @var {$entity}[] \$result */
                        \$result = parent::findAll(\$criteria);
                        return \$result;
                    }

                    /**
                     * Typed wrapper around parent::findOneBy().
                     *
                     * @param array<string,mixed> \$criteria
                     */
                    public function findOneBy(array \$criteria): ?{$entity}
                    {
                        /** @var ?{$entity} \$result */
                        \$result = parent::findOneBy(\$criteria);
                        return \$result;
                    }
                }
                    
                PHP;

        file_put_contents($file, $code);
        $this->info("✅  Created stub $file");
    }

    /** Does $body already contain `public … $prop`? */
    private function hasProperty(string $body, string $prop): bool
    {
        return (bool) preg_match('/public\s+(?:\?\w+|array)\s+\$' . preg_quote($prop, '/') . '\b/', $body);
    }

    /**
     * Convert a class name to snake_case.
     *
     * This method takes a class name (e.g., "MyClassName") and converts it to snake_case (e.g., "my_class_name").
     *
     * @param string $class The class name to convert.
     *
     * @return string The converted class name.
     *
     */
    private function snake(string $class): string
    {
        return strtolower(
            preg_replace('/([a-z])([A-Z])/', '\$1_\$2', $class)
        );
    }
}
