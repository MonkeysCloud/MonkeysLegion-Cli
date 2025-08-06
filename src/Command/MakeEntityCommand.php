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
use MonkeysLegion\Cli\Config\PhpTypeMap;
use MonkeysLegion\Cli\Config\RelationInverseMap;
use MonkeysLegion\Cli\Config\RelationKeywordMap;
use MonkeysLegion\Cli\Config\RelationKind;
use MonkeysLegion\Cli\Helpers\Identifier;
use MonkeysLegion\Cli\Service\ClassManipulator;
use PhpParser\ParserFactory;
use PhpParser\NodeFinder;

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

    /** @var array<string> DB scalar types offered in the wizard */
    private array $fieldTypes;

    private RelationKeywordMap $relTypes;

    /**
     * Maps each owning-side relation kind (e.g., ONE_TO_MANY)
     * to its corresponding inverse relation kind (e.g., MANY_TO_ONE).
     */
    private RelationInverseMap $inverseMap;

    /**
     * Maps DB field types (e.g., "string", "json")
     * to their corresponding PHP native types (e.g., "string", "array").
     */
    private PhpTypeMap $phpTypeMap;

    /** @var array<string, string> Field names in the current entity */
    protected array $fieldNames = [];

    /** @var array<string, array{target: string, attr: RelationKind|string, other_prop: string|null, owning: bool|null, joinTable: JoinTable|null}> */
    protected array $relNames = [];

    public function __construct(
        private EntityConfig $config,
    ) {
        parent::__construct();

        $this->fieldTypes = array_map(fn(FieldType $case) => $case->value, $this->config->fieldTypes->all());

        $this->relTypes = $this->config->relationKeywordMap;

        $this->inverseMap = $this->config->relationInverseMap;

        $this->phpTypeMap = $this->config->phpTypeMap;
    }

    /* helpers */
    private string $completionContext = CompletionContext::DEFAULT->value;

    /** @var array<string, array<string>> */
    private array $contextAwareCompletions = [];

    /**
     * @var array<string, array<int, array{
     *   prop: string,
     *   attr: string|RelationKind,
     *   target: string,
     *   other_prop: string|null,
     *   inverse_o2o: bool|null,
     *   joinTable?: JoinTable|null
     * }>>
     */
    private array $inverseQueue = [];

    /** @var string[] */
    private array $inverseShouldBePlural = [
        RelationKind::ONE_TO_MANY->value,
        RelationKind::MANY_TO_MANY->value,
    ];

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
        $entityFiles = glob($dir . '/*.php') ?: [];
        $entities = [];
        foreach ($entityFiles as $filePath) {
            // Get filename without extension, e.g. User.php -> User
            $fileName = basename($filePath, '.php');
            $entities[$fileName] = $fileName;
        }
        $this->setCompletionContext(CompletionContext::ENTITY, array_keys($entities));

        /* 1️⃣  entity name */
        $argv = $_SERVER['argv'] ?? [];

        $name = (is_array($argv) && isset($argv[2]) && is_string($argv[2]))
            ? $argv[2]
            : $this->ask('Enter entity name (e.g. User)');

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
        $src = file_get_contents($file) ?: '';
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($src);
        $existingFields = [];
        $existingRels = [];
        if ($ast) {
            $class = (new NodeFinder())->findFirstInstanceOf($ast, \PhpParser\Node\Stmt\Class_::class);
            if ($class) {
                foreach ($class->stmts as $stmt) {
                    if ($stmt instanceof \PhpParser\Node\Stmt\Property) {
                        $propName = $stmt->props[0]->name->name;
                        foreach ($stmt->attrGroups as $attrGroup) {
                            foreach ($attrGroup->attrs as $attr) {
                                $attrName = $attr->name->toString();
                                if ($attrName === 'Field') {
                                    $existingFields[] = $propName;
                                }
                                if (in_array($attrName, array_values($this->relTypes->all()), true)) {
                                    $existingRels[] = $propName;
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
        foreach ($this->fieldNames as $p => $def) {
            if (!is_array($def)) {
                // backward compatibility in case any old entries exist
                $def = ['db' => (string)$def, 'nullable' => false];
            }
            $t = $def['db'];
            $nullable = (bool)($def['nullable'] ?? false);

            $fieldType = FieldType::tryFrom($t);
            if (!$fieldType) {
                $this->error("Unknown field type '$t' for property '$p'.");
                continue;
            }
            $phpType = $this->phpTypeMap->map($fieldType);
            $manipulator->addScalarField($p, $t, $phpType, $nullable);
        }
        foreach ($this->relNames as $p => $m) {
            $short = substr($m['target'], strrpos($m['target'], '\\') + 1);
            $kindEnum = $m['attr'] instanceof RelationKind ? $m['attr'] : ClassManipulator::toEnum($m['attr']);
            $isMany   = in_array($kindEnum, [RelationKind::ONE_TO_MANY, RelationKind::MANY_TO_MANY], true);

            $manipulator->addRelation(
                $p,
                $kindEnum,
                $short,
                $m['owning'] ?? true,
                $m['other_prop'] ?? null,
                $m['joinTable'] ?? null,
                $kindEnum === RelationKind::ONE_TO_ONE && !($m['owning'] ?? true),
                $m['nullable'] ?? false
            );
        }
        $manipulator->save();
        $this->info("✅  Updated $file");

        /* 8️⃣  inverse patch */
        $this->applyInverseQueue();
        return self::SUCCESS;
    }

    /**
     * @param array<string, array<string, string>> $existing Existing properties to check against.
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
        $nullable = strtolower($this->ask('  Nullable? [y/N]')) === 'y';
        $this->fieldNames[$prop] = [
            'db'       => $type,
            'nullable' => $nullable,
        ];
        $this->info("  ➕  $prop:$type added.");
    }

    /**
     * Prompt the user for a relationship type and target entity.
     *
     * @param array<string, array<string, string>> $existing.
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
        $this->printRelationHelp($relCase);
        $attr = $relCase->value;
        $attrUC = $kind;
        $isCollection = in_array(
            $attr,
            [RelationKind::ONE_TO_MANY->value, RelationKind::MANY_TO_MANY->value],
            true
        );
        if ($isCollection) {
            // collections always get initialized to [] so never nullable
            $nullable = false;
        } else {
            // to-one relations: ask the user
            $nullable = strtolower($this->ask('  Is this relation optional/nullable? [y/N]')) === 'y';
        }

        /* target entity */
        $files = glob(base_path('app/Entity') . '/*.php') ?: [];
        $entities = array_map(
            fn($f) => basename($f, '.php'),
            $files
        );
        $this->setCompletionContext(CompletionContext::ENTITY, $entities);
        $target = $this->ask('  Target entity');
        if (!Identifier::isValid($target)) {
            $this->error('Invalid entity name.');
            return;
        }

        $short = str_contains($target, '\\') ? substr($target, strrpos($target, '\\') + 1) : $target;
        $fqcn  = str_contains($target, '\\') ? $target : "App\\Entity\\$target";

        echo "  ➕  $attrUC relation to $fqcn\n";
        if (in_array($attr, $this->inverseShouldBePlural, true)) {
            // plural suggestion for collections
            $suggest = lcfirst($this->inflector->pluralize($short));
        } else {
            // singular for 1-to-1 / many-to-1
            $suggest = lcfirst($short);
        }

        $joinTable = null;
        /* property name */
        if ($attr === RelationKind::MANY_TO_MANY->value) {
            // ── Defaults ────────────────────────────────────────────────
            $snakeSelf   = $this->snake(lcfirst($selfClass));   // e.g. "user"
            $snakeTarget = $this->snake(lcfirst($short));       // e.g. "role"

            // Table name: keep alphabetical for stability
            $tblParts   = [$snakeSelf, $snakeTarget];
            sort($tblParts);                                    // only for table
            $defaultTbl = implode('_', $tblParts);              // "role_user"

            // ── Prompt ────────────────────────────────────────────────
            $tbl  = $this->ask("  Join table name [$defaultTbl]") ?: $defaultTbl;

            // Column names must map to the correct side (no sorting here!)
            $colA = $this->ask("  Column for {$selfClass} [{$snakeSelf}_id]")
                ?: "{$snakeSelf}_id";                       // "user_id"
            $colB = $this->ask("  Column for {$short} [{$snakeTarget}_id]")
                ?: "{$snakeTarget}_id";                     // "role_id"

            // Build JoinTable metadata
            $joinTable = new JoinTable(
                name:          $tbl,
                joinColumn:    $colA,   // FK to *this* entity
                inverseColumn: $colB    // FK to target entity
            );

            // ── M2M specifics ──────────────────────────────────────────
            $nullable = false;  // collections are never nullable
            $owning   = true;   // current side owns the join table
        }
        $prop = $this->ask("  Property name [$suggest]") ?: $suggest;
        if ($prop === '' || isset($existing[$prop]) || isset($this->relNames[$prop])) return;

        /* inverse side? */
        $this->line("ℹ️  You can generate the inverse side now. The tool will set");
        $this->line("   mappedBy/inversedBy and ownership based on the relation kind.");
        $inverseProp = null;
        $invAttr = null;
        if (strtolower($this->ask('  Generate inverse side in target? [y/N]')) === 'y') {
            $invRelationKind = $this->inverseMap->getInverse($relCase);
            if ($invRelationKind === null) {
                $this->error('Cannot determine inverse relationship.');
                return;
            }
            $invAttr = lcfirst($invRelationKind->value);

            if (in_array($invAttr, $this->inverseShouldBePlural, true)) {
                // proper plural based on the current entity name, e.g. "users"
                $defName = lcfirst($this->inflector->pluralize($selfClass));
            } else {
                // singular based on the current entity name
                $defName = lcfirst($selfClass);
            }

            $inverseProp = $this->ask("  Inverse property in $short [{$defName}]") ?: $defName;
            $isInverseOneToOne = $relCase === RelationKind::ONE_TO_ONE;
            $fqSelf = "App\\Entity\\$selfClass";
            $invOwning = match ($invRelationKind) {
                RelationKind::ONE_TO_MANY  => false,
                RelationKind::MANY_TO_ONE  => true,
                RelationKind::ONE_TO_ONE   => false, // inverse O2O uses mappedBy → not owning
                RelationKind::MANY_TO_MANY => false, // let current side be owning
            };
            $this->queueInverse(
                $fqcn,
                $inverseProp,
                $invRelationKind->value,
                $fqSelf,
                $prop,
                $isInverseOneToOne,
                $invOwning
            );
            $owning = true;
        }

        $this->relNames[$prop] = [
            'attr'       => $relCase,
            'target'     => $fqcn,
            'other_prop' => $inverseProp,
            'joinTable'  => $joinTable,
            'nullable'   => $nullable,
            'owning'     => $owning,
        ];

        $this->info("  ➕  $selfClass::$prop ($kind) --> $fqcn");
        if ($inverseProp) {
            $this->info("  ↪  inverse in $fqcn::$inverseProp ({$invRelationKind->value})");
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
        bool    $isInverseOneToOne = false,
        bool    $owning = false
    ): void {
        $this->inverseQueue[$fqcn][] = [
            'prop'       => $prop,
            'attr'       => $attr,
            'target'     => $target,
            'other_prop' => $otherProp,
            'inverse_o2o' => $isInverseOneToOne,
            'owning'      => $owning,
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
            $manipulator = new ClassManipulator($file);

            foreach ($defs as $d) {
                $targetClass = substr($d['target'], strrpos($d['target'], '\\') + 1);
                $kindEnum = $d['attr'] instanceof RelationKind ? $d['attr'] : ClassManipulator::toEnum($d['attr']);
                $isMany   = in_array($kindEnum, [RelationKind::ONE_TO_MANY, RelationKind::MANY_TO_MANY], true);
                if ($d['prop'] === 'id' && $isMany) {
                    $d['prop'] = lcfirst($this->inflector->pluralize($targetClass));
                }
                // Decide owning for the inverse when not explicitly stored
                $owning = $d['owning'] ?? match ($kindEnum) {
                    RelationKind::ONE_TO_MANY  => false,
                    RelationKind::MANY_TO_ONE  => true,
                    RelationKind::ONE_TO_ONE   => !($d['inverse_o2o'] ?? false),
                    RelationKind::MANY_TO_MANY => false,
                };
                $manipulator->addRelation(
                    $d['prop'],
                    $kindEnum,
                    $targetClass,
                    $owning,
                    $d['other_prop'] ?? null,
                    $d['joinTable'] ?? null,
                    $d['inverse_o2o'] ?? false,
                    true
                );
            }
            $manipulator->save();
            $this->info("    ↪  Patched inverse side in $file");
        }
    }

    /**
     * Displays a message to the user.
     *
     * @param string $in
     * @param int $i
     * @return array<string>
     */
    public function readlineComplete(string $in, int $i): array
    {
        $opts = array_filter(
            $this->contextAwareCompletions[$this->completionContext] ?? [],
            fn($o) => $this->fuzzyMatch($in, $o)
        );

        $count = count($opts);

        if ($count > 0) {
            usort($opts, function (string $a, string $b) use ($in) {
                similar_text($in, $b, $percentB);
                similar_text($in, $a, $percentA);
                return $percentB <=> $percentA;
            });

            echo PHP_EOL . "Suggestions: " . implode(" | ", $opts) . PHP_EOL;
            echo "> " . $in;
        }

        // Cast everything to string explicitly to satisfy return type
        return $count >= 1 ? array_map('strval', [$in, ...$opts]) : [$in];
    }

    /**
     * Single completer that adapts based on current context
     *
     * @param string $input The user input to complete.
     * @param int $index The index of the current completion.
     * @return array<string> An array of completion suggestions.
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

    /**
     * Sets the completion context and its associated completions.
     *
     * This method updates the current completion context and stores any additional
     * completions that should be available in that context.
     *
     * @param CompletionContext $context The new completion context to set.
     * @param array<string> $completions Optional additional completions for the context.
     */
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
     * @return array<string> An array of suggestions including the original input.
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
     * @param array<string> $opts
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
     * Generate a repository class stub for a given entity.
     *
     * @param string $entity  Short class name of the entity (e.g. “User”)
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

        // a simple table guess – adapt if you have another rule
        $table = strtolower($entity);

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
            /** @var non-empty-string */
            protected string \$table       = '{$table}';
            protected string \$entityClass = {$entity}::class;

            // ──────────────────────────────────────────────────────────
            //  Typed convenience wrappers (optional)
            //  Keep them if you like the stricter return types; otherwise
            //  feel free to delete them and rely on the parent methods.
            // ──────────────────────────────────────────────────────────

            /**
             * @param array<string,mixed> \$criteria
             * @return {$entity}[]
             */
            public function findAll(
                array \$criteria = [],
                bool  \$loadRelations = true
            ): array {
                /** @var {$entity}[] \$rows */
                \$rows = parent::findAll(\$criteria, \$loadRelations);
                return \$rows;
            }

            /**
             * @param array<string,mixed> \$criteria
             */
            public function findOneBy(
                array \$criteria,
                bool  \$loadRelations = true
            ): ?{$entity} {
                /** @var ?{$entity} \$row */
                \$row = parent::findOneBy(\$criteria, \$loadRelations);
                return \$row;
            }
        }

        PHP;

        file_put_contents($file, $code);
        $this->info("✅  Created stub $file");
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
            preg_replace('/([a-z])([A-Z])/', '$1_$2', $class) ?? ''
        );
    }

    /**
     * Print help for the given relation kind.
     *
     * This method provides detailed information about how to set up the specified relation kind,
     * including which side is owning, how to use mappedBy/inversedBy, and what the expected result is.
     *
     * @param RelationKind $kind The relation kind to provide help for.
     */
    private function printRelationHelp(RelationKind $kind): void
    {
        switch ($kind) {
            case RelationKind::ONE_TO_MANY:
                $this->line("\n📘 OneToMany");
                $this->line("• Collection side (OneToMany) is the *inverse* side → uses mappedBy.");
                $this->line("• The *owning* side is ManyToOne on the target entity → uses inversedBy and carries the FK `<prop>_id`.");
                $this->line("• Result: current entity gets `#[OneToMany(targetEntity: X::class, mappedBy: 'y')] array`.");
                $this->line("         target gets `#[ManyToOne(targetEntity: Current::class, inversedBy: 'plural')] ?Current`.\n");
                break;

            case RelationKind::MANY_TO_ONE:
                $this->line("\n📘 ManyToOne");
                $this->line("• This side is the *owning* side → uses inversedBy and holds the FK `<prop>_id`.");
                $this->line("• The inverse side on the other entity is OneToMany → mappedBy and an array collection.");
                $this->line("• Result: current entity gets `#[ManyToOne(targetEntity: X::class, inversedBy: 'plural')] ?X`.");
                $this->line("         target gets `#[OneToMany(targetEntity: Current::class, mappedBy: 'singular')] array`.\n");
                break;

            case RelationKind::ONE_TO_ONE:
                $this->line("\n📘 OneToOne");
                $this->line("• Only one side owns the relation. The owning side stores the FK `<prop>_id`.");
                $this->line("• Owning side → `inversedBy`. Inverse side → `mappedBy`.");
                $this->line("• If you generate the inverse, the tool will set it as `mappedBy` automatically.\n");
                break;

            case RelationKind::MANY_TO_MANY:
                $this->line("\n📘 ManyToMany");
                $this->line("• One side must be chosen as *owning* to define the join table (name + columns).");
                $this->line("• Owning side → may specify `joinTable`. Inverse side → just `mappedBy`/`inversedBy`.");
                $this->line("• Both sides are arrays (collections). No FK column on either main table; rows live in the join table.\n");
                break;
        }
    }
}
