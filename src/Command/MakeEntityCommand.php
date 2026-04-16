<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use Doctrine\Inflector\Inflector;
use Doctrine\Inflector\InflectorFactory;
use MonkeysLegion\Cli\Config\EntityConfig;
use MonkeysLegion\Cli\Config\FieldType;
use MonkeysLegion\Cli\Config\PhpTypeMap;
use MonkeysLegion\Cli\Config\RelationInverseMap;
use MonkeysLegion\Cli\Config\RelationKeywordMap;
use MonkeysLegion\Cli\Config\RelationKind;
use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cli\Helpers\Identifier;
use MonkeysLegion\Cli\Service\ClassManipulator;
use MonkeysLegion\Entity\Attributes\JoinTable;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Generate or update an Entity class using an interactive wizard,
 * or non-interactively via `--fields="name:string,email:string:nullable"`.
 *
 * v2 improvements:
 *  • Generates PHP 8.4 entities with `#[Id]` + `#[Field]` dual attributes
 *  • Uses `public private(set)` for asymmetric visibility
 *  • Supports `--fields` for CI / non-interactive usage
 *  • Uses v2 Console helpers (table, choice, alert)
 *  • Cleaner wizard flow with better UX
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('make:entity', 'Generate or update an Entity class with fields & relationships')]
final class MakeEntityCommand extends Command
{
    use MakerHelpers;

    /** @var list<string> DB field type values for the wizard */
    private array $fieldTypeValues;

    private RelationKeywordMap $relTypes;
    private RelationInverseMap $inverseMap;
    private PhpTypeMap $phpTypeMap;
    private Inflector $inflector;

    /** @var array<string, array{db: string, nullable: bool}> Fields added in this session */
    private array $fieldNames = [];

    /** @var array<string, array{attr: RelationKind, target: string, other_prop: ?string, owning: bool, joinTable: ?JoinTable, nullable: bool}> */
    private array $relNames = [];

    /** @var array<string, list<array{prop: string, attr: string|RelationKind, target: string, other_prop: ?string, inverse_o2o: bool, owning: bool}>> */
    private array $inverseQueue = [];

    /** @var list<string> Relation kinds that map to array collections */
    private array $inverseShouldBePlural;

    public function __construct(
        private readonly EntityConfig $config,
    ) {
        parent::__construct();

        $this->fieldTypeValues = array_map(
            static fn(FieldType $case): string => $case->value,
            $this->config->fieldTypes->all(),
        );
        $this->relTypes    = $this->config->relationKeywordMap;
        $this->inverseMap  = $this->config->relationInverseMap;
        $this->phpTypeMap  = $this->config->phpTypeMap;
        $this->inverseShouldBePlural = [
            RelationKind::ONE_TO_MANY->value,
            RelationKind::MANY_TO_MANY->value,
        ];
    }

    // ── Entry point ─────────────────────────────────────────

    protected function handle(): int
    {
        $this->inflector = InflectorFactory::create()->build();
        $dir = function_exists('base_path') ? base_path('app/Entity') : 'app/Entity';

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        // ── Entity name ────────────────────────────────────
        $name = $this->argument(0) ?? $this->ask('Entity name (e.g., User):');

        if (!preg_match('/^[A-Z][A-Za-z0-9]+$/', $name)) {
            return $this->fail('Invalid class name — must start with uppercase.');
        }

        // ── Ensure file exists ─────────────────────────────
        $file = "{$dir}/{$name}.php";

        if (!is_file($file)) {
            $this->createStub($name, $file);
            $this->info("✅ Created stub: {$file}");
            $this->createRepoStub($name);
        }

        // ── Non-interactive mode (--fields) ────────────────
        $fieldsOption = $this->option('fields');

        if (is_string($fieldsOption) && $fieldsOption !== '') {
            return $this->handleNonInteractive($file, $name, $fieldsOption);
        }

        // ── Interactive wizard ─────────────────────────────
        return $this->handleInteractive($file, $name);
    }

    // ── Non-interactive mode ────────────────────────────────

    /**
     * Parse `--fields="name:string,email:string:nullable,age:integer"` and apply.
     */
    private function handleNonInteractive(string $file, string $name, string $fieldsStr): int
    {
        $manipulator = new ClassManipulator($file);
        $specs       = explode(',', $fieldsStr);

        foreach ($specs as $spec) {
            $parts    = explode(':', trim($spec));
            $propName = $parts[0] ?? '';
            $dbType   = $parts[1] ?? 'string';
            $nullable = in_array('nullable', $parts, true);

            if (!Identifier::isValid($propName)) {
                $this->warn("Skipped invalid field: {$propName}");

                continue;
            }

            $fieldType = FieldType::tryFrom($dbType);

            if (!$fieldType) {
                $this->warn("Unknown type '{$dbType}' for '{$propName}', defaulting to 'string'.");
                $fieldType = FieldType::STRING;
                $dbType    = 'string';
            }

            $phpType = $this->phpTypeMap->map($fieldType);
            $manipulator->addScalarField($propName, $dbType, $phpType, $nullable);
            $this->cliLine()
                ->add('  ✓ ', 'green')
                ->add("{$propName}", 'white')
                ->add(":{$dbType}", 'cyan')
                ->add($nullable ? ' (nullable)' : '', 'yellow')
                ->print();
        }

        $manipulator->save();
        $this->info("✅ Updated: {$file}");

        return self::SUCCESS;
    }

    // ── Interactive wizard ──────────────────────────────────

    private function handleInteractive(string $file, string $name): int
    {
        $manipulator = new ClassManipulator($file);

        // Scan existing properties
        $existing = $this->scanExistingProperties($file);

        // Wizard loop
        while (true) {
            $this->newLine();
            $this->cliLine()
                ->add('══════ ', 'cyan')
                ->add("Entity: {$name}", 'white', 'bold')
                ->add(' ══════', 'cyan')
                ->print();

            $choice = $this->choice('What would you like to do?', [
                'Add field',
                'Add relationship',
                'Finish & save',
            ], 0);

            match ($choice) {
                'Add field'        => $this->wizardField($existing['fields']),
                'Add relationship' => $this->wizardRelation($existing['rels'], $name),
                'Finish & save'    => null,
            };

            if ($choice === 'Finish & save') {
                break;
            }
        }

        if ($this->fieldNames === [] && $this->relNames === []) {
            $this->info('No changes.');

            return self::SUCCESS;
        }

        // Apply fields
        foreach ($this->fieldNames as $p => $def) {
            $fieldType = FieldType::tryFrom($def['db']);

            if (!$fieldType) {
                $this->error("Unknown field type '{$def['db']}' for property '{$p}'.");

                continue;
            }

            $phpType = $this->phpTypeMap->map($fieldType);
            $manipulator->addScalarField($p, $def['db'], $phpType, $def['nullable']);
        }

        // Apply relations
        foreach ($this->relNames as $p => $m) {
            $short    = substr($m['target'], strrpos($m['target'], '\\') + 1);
            $kindEnum = $m['attr'] instanceof RelationKind ? $m['attr'] : ClassManipulator::toEnum($m['attr']);

            $manipulator->addRelation(
                $p,
                $kindEnum,
                $short,
                $m['owning'],
                $m['other_prop'] ?? null,
                $m['joinTable'] ?? null,
                $kindEnum === RelationKind::ONE_TO_ONE && !$m['owning'],
                $m['nullable'],
            );
        }

        $manipulator->save();
        $this->info("✅ Updated: {$file}");

        // Apply inverse queue
        $this->applyInverseQueue();

        return self::SUCCESS;
    }

    // ── Wizard: field ───────────────────────────────────────

    /**
     * @param array<string, true> $existing
     */
    private function wizardField(array $existing): void
    {
        $prop = $this->ask('  Field name:');

        if (!Identifier::isValid($prop)) {
            $this->error('Invalid identifier.');

            return;
        }

        if (isset($existing[$prop]) || isset($this->fieldNames[$prop])) {
            $this->error("Field '{$prop}' already exists.");

            return;
        }

        $type     = $this->choice('  Type:', $this->fieldTypeValues, 0);
        $nullable = $this->confirm('  Nullable?', false);

        $this->fieldNames[$prop] = [
            'db'       => $type,
            'nullable' => $nullable,
        ];

        $this->cliLine()
            ->add('  ➕ ', 'green')
            ->add("{$prop}:{$type}", 'white')
            ->add($nullable ? ' (nullable)' : '', 'yellow')
            ->print();
    }

    // ── Wizard: relation ────────────────────────────────────

    /**
     * @param array<string, true> $existing
     */
    private function wizardRelation(array $existing, string $selfClass): void
    {
        $opts    = $this->relTypes->all();
        $kind    = $this->choice('  Relation type:', array_keys($opts), 0);
        $relCase = $this->relTypes->tryFrom($kind);

        if (!$relCase) {
            $this->error('Unknown relation type.');

            return;
        }

        $this->printRelationHelp($relCase);
        $attr = $relCase->value;

        $isCollection = in_array($attr, $this->inverseShouldBePlural, true);
        $nullable     = $isCollection ? false : $this->confirm('  Optional/nullable?', false);

        // Target entity
        $files    = glob((function_exists('base_path') ? base_path('app/Entity') : 'app/Entity') . '/*.php') ?: [];
        $entities = array_map(static fn(string $f): string => basename($f, '.php'), $files);

        $target = $this->ask('  Target entity:');

        if (!Identifier::isValid($target) && !preg_match('/^[A-Z][A-Za-z0-9]+$/', $target)) {
            $this->error('Invalid entity name.');

            return;
        }

        $short = str_contains($target, '\\') ? substr($target, strrpos($target, '\\') + 1) : $target;
        $fqcn  = str_contains($target, '\\') ? $target : "App\\Entity\\{$target}";

        // Property name suggestion
        $suggest = in_array($attr, $this->inverseShouldBePlural, true)
            ? lcfirst($this->inflector->pluralize($short))
            : lcfirst($short);

        $joinTable = null;
        $owning    = true;

        // M2M join table setup
        if ($attr === RelationKind::MANY_TO_MANY->value) {
            $snakeSelf   = $this->snake(lcfirst($selfClass));
            $snakeTarget = $this->snake(lcfirst($short));

            $tblParts = [$snakeSelf, $snakeTarget];
            sort($tblParts);
            $defaultTbl = implode('_', $tblParts);

            $tbl  = $this->ask("  Join table [{$defaultTbl}]:") ?: $defaultTbl;
            $colA = $this->ask("  Column for {$selfClass} [{$snakeSelf}_id]:") ?: "{$snakeSelf}_id";
            $colB = $this->ask("  Column for {$short} [{$snakeTarget}_id]:") ?: "{$snakeTarget}_id";

            $joinTable = new JoinTable(name: $tbl, joinColumn: $colA, inverseColumn: $colB);
            $nullable  = false;
            $owning    = true;
        }

        $prop = $this->ask("  Property name [{$suggest}]:") ?: $suggest;

        if ($prop === '' || isset($existing[$prop]) || isset($this->relNames[$prop])) {
            return;
        }

        // Inverse side
        $inverseProp = null;
        $invRelationKind = null;

        if ($this->confirm('  Generate inverse side in target?', false)) {
            $invRelationKind = $this->inverseMap->getInverse($relCase);

            if (!$invRelationKind) {
                $this->error('Cannot determine inverse relationship.');

                return;
            }

            $invAttr = lcfirst($invRelationKind->value);
            $defName = in_array($invAttr, $this->inverseShouldBePlural, true)
                ? lcfirst($this->inflector->pluralize($selfClass))
                : lcfirst($selfClass);

            $inverseProp = $this->ask("  Inverse property in {$short} [{$defName}]:") ?: $defName;

            $invOwning = match ($invRelationKind) {
                RelationKind::ONE_TO_MANY  => false,
                RelationKind::MANY_TO_ONE  => true,
                RelationKind::ONE_TO_ONE   => false,
                RelationKind::MANY_TO_MANY => false,
            };

            $this->queueInverse(
                $fqcn,
                $inverseProp,
                $invRelationKind->value,
                "App\\Entity\\{$selfClass}",
                $prop,
                $relCase === RelationKind::ONE_TO_ONE,
                $invOwning,
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

        $this->cliLine()
            ->add('  ➕ ', 'green')
            ->add("{$selfClass}::{$prop}", 'white')
            ->add(" ({$kind})", 'cyan')
            ->add(" → {$fqcn}", 'yellow')
            ->print();

        if ($inverseProp && $invRelationKind) {
            $this->cliLine()
                ->add('  ↪ ', 'blue')
                ->add("inverse: {$fqcn}::{$inverseProp}", 'white')
                ->add(" ({$invRelationKind->value})", 'cyan')
                ->print();
        }
    }

    // ── Inverse queue ───────────────────────────────────────

    private function queueInverse(
        string $fqcn,
        string $prop,
        string $attr,
        string $target,
        ?string $otherProp = null,
        bool $isInverseOneToOne = false,
        bool $owning = false,
    ): void {
        $this->inverseQueue[$fqcn][] = [
            'prop'        => $prop,
            'attr'        => $attr,
            'target'      => $target,
            'other_prop'  => $otherProp,
            'inverse_o2o' => $isInverseOneToOne,
            'owning'      => $owning,
        ];
    }

    private function applyInverseQueue(): void
    {
        $entityDir = function_exists('base_path') ? base_path('app/Entity') : 'app/Entity';

        foreach ($this->inverseQueue as $fqcn => $defs) {
            $short = substr($fqcn, strrpos($fqcn, '\\') + 1);
            $file  = "{$entityDir}/{$short}.php";

            if (!is_file($file)) {
                $this->createStub($short, $file);
            }

            $manipulator = new ClassManipulator($file);

            foreach ($defs as $d) {
                $targetClass = substr($d['target'], strrpos($d['target'], '\\') + 1);
                $kindEnum    = $d['attr'] instanceof RelationKind
                    ? $d['attr']
                    : ClassManipulator::toEnum($d['attr']);
                $isMany = in_array($kindEnum, [RelationKind::ONE_TO_MANY, RelationKind::MANY_TO_MANY], true);

                if ($d['prop'] === 'id' && $isMany) {
                    $d['prop'] = lcfirst($this->inflector->pluralize($targetClass));
                }

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
                    true,
                );
            }

            $manipulator->save();
            $this->cliLine()
                ->add('  ↪ ', 'blue')
                ->add("Patched inverse: {$file}", 'white')
                ->print();
        }
    }

    // ── Property scanning ───────────────────────────────────

    /**
     * Scan existing entity file for #[Field] and relation properties.
     *
     * @return array{fields: array<string, true>, rels: array<string, true>}
     */
    private function scanExistingProperties(string $file): array
    {
        $src = file_get_contents($file) ?: '';
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($src);

        $fields = [];
        $rels   = [];

        if (!$ast) {
            return ['fields' => $fields, 'rels' => $rels];
        }

        $class = (new NodeFinder())->findFirstInstanceOf($ast, \PhpParser\Node\Stmt\Class_::class);

        if (!$class) {
            return ['fields' => $fields, 'rels' => $rels];
        }

        $relationAttrs = array_values($this->relTypes->all());

        foreach ($class->stmts as $stmt) {
            if (!$stmt instanceof \PhpParser\Node\Stmt\Property) {
                continue;
            }

            $propName = $stmt->props[0]->name->name;

            foreach ($stmt->attrGroups as $attrGroup) {
                foreach ($attrGroup->attrs as $attr) {
                    $attrName = $attr->name->toString();

                    if ($attrName === 'Field') {
                        $fields[$propName] = true;
                    }

                    if (in_array($attrName, $relationAttrs, true)) {
                        $rels[$propName] = true;
                    }
                }
            }
        }

        return ['fields' => $fields, 'rels' => $rels];
    }

    // ── Stubs ───────────────────────────────────────────────

    /**
     * v2 entity stub with #[Id] + #[Field] and private(set).
     */
    private function createStub(string $name, string $file): void
    {
        $template = <<<PHP
            <?php
            declare(strict_types=1);

            namespace App\\Entity;

            use MonkeysLegion\\Entity\\Attributes\\Entity;
            use MonkeysLegion\\Entity\\Attributes\\Field;
            use MonkeysLegion\\Entity\\Attributes\\Id;
            use MonkeysLegion\\Entity\\Attributes\\OneToOne;
            use MonkeysLegion\\Entity\\Attributes\\OneToMany;
            use MonkeysLegion\\Entity\\Attributes\\ManyToOne;
            use MonkeysLegion\\Entity\\Attributes\\ManyToMany;
            use MonkeysLegion\\Entity\\Attributes\\JoinTable;

            #[Entity]
            class {$name}
            {
                #[Id]
                #[Field(type: 'unsignedBigInt', autoIncrement: true)]
                public private(set) int \$id;

                public function __construct()
                {
                }
            }

            PHP;

        file_put_contents($file, $template);
    }

    /**
     * Generate a typed repository stub alongside the entity.
     */
    private function createRepoStub(string $entity): void
    {
        $dir  = function_exists('base_path') ? base_path('app/Repository') : 'app/Repository';
        $file = "{$dir}/{$entity}Repository.php";

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        if (is_file($file)) {
            $this->comment("  Repository already exists: {$file}");

            return;
        }

        $table = strtolower($this->inflector->pluralize($entity));

        $code = <<<PHP
            <?php
            declare(strict_types=1);

            namespace App\\Repository;

            use MonkeysLegion\\Repository\\EntityRepository;
            use App\\Entity\\{$entity};

            /**
             * @extends EntityRepository<{$entity}>
             */
            class {$entity}Repository extends EntityRepository
            {
                /** @var non-empty-string */
                protected string \$table       = '{$table}';
                protected string \$entityClass = {$entity}::class;

                /**
                 * @param array<string, mixed> \$criteria
                 * @return list<{$entity}>
                 */
                public function findAll(
                    array \$criteria = [],
                    bool \$loadRelations = true,
                ): array {
                    /** @var list<{$entity}> \$rows */
                    \$rows = parent::findAll(\$criteria, \$loadRelations);

                    return \$rows;
                }

                /**
                 * @param array<string, mixed> \$criteria
                 */
                public function findOneBy(
                    array \$criteria,
                    bool \$loadRelations = true,
                ): ?{$entity} {
                    /** @var ?{$entity} \$row */
                    \$row = parent::findOneBy(\$criteria, \$loadRelations);

                    return \$row;
                }
            }

            PHP;

        file_put_contents($file, $code);
        $this->info("✅ Created repository: {$file}");
    }

    // ── Relation help ───────────────────────────────────────

    private function printRelationHelp(RelationKind $kind): void
    {
        match ($kind) {
            RelationKind::ONE_TO_MANY => $this->comment(
                "  📘 OneToMany — Collection is *inverse* (mappedBy). Target gets ManyToOne (inversedBy + FK).",
            ),
            RelationKind::MANY_TO_ONE => $this->comment(
                "  📘 ManyToOne — *Owning* side (inversedBy + FK). Target gets OneToMany (mappedBy).",
            ),
            RelationKind::ONE_TO_ONE => $this->comment(
                "  📘 OneToOne — One side owns FK. Owning → inversedBy. Inverse → mappedBy.",
            ),
            RelationKind::MANY_TO_MANY => $this->comment(
                "  📘 ManyToMany — One side is owning (defines join table). Both sides are collections.",
            ),
        };
    }

    // ── Helpers ──────────────────────────────────────────────

    private function snake(string $class): string
    {
        return strtolower(
            preg_replace('/([a-z])([A-Z])/', '$1_$2', $class) ?? '',
        );
    }
}
