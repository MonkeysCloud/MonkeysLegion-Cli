<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Entity\Attributes\JoinTable;
use Doctrine\Inflector\Inflector;
use Doctrine\Inflector\InflectorFactory;

#[CommandAttr('make:entity', 'Generate or update an Entity class with fields & relationships')]
final class MakeEntityCommand extends Command
{

    /** @var string[] DB scalar types offered in the wizard */
    private array $fieldTypes = [
        'string','char','text','mediumText','longText',
        'integer','tinyInt','smallInt','bigInt','unsignedBigInt',
        'decimal','float','boolean',
        'date','time','datetime','datetimetz','timestamp','timestamptz','year',
        'uuid','binary','json','simple_json','array','simple_array',
        'enum','set','geometry','point','linestring','polygon',
        'ipAddress','macAddress',
    ];

    /** CLI keyword → attribute class */
    private array $relTypes = [
        'oneToOne'   => 'OneToOne',
        'oneToMany'  => 'OneToMany',
        'manyToOne'  => 'ManyToOne',
        'manyToMany' => 'ManyToMany',
    ];

    /** owning-side attribute → inverse attribute */
    private array $inverseMap = [
        'OneToOne'   => 'OneToOne',
        'ManyToOne'  => 'OneToMany',
        'OneToMany'  => 'ManyToOne',
        'ManyToMany' => 'ManyToMany',
    ];

    /** DB type → PHP type */
    private array $phpTypeMap = [
        /* text  */ 'string'=>'string','char'=>'string','text'=>'string',
        'mediumText'=>'string','longText'=>'string',
        /* nums  */ 'integer'=>'int','tinyInt'=>'int','smallInt'=>'int','bigInt'=>'int',
        'unsignedBigInt'=>'int','decimal'=>'float','float'=>'float','boolean'=>'bool','year'=>'int',
        /* date  */ 'date'=>'\DateTimeImmutable','time'=>'\DateTimeImmutable','datetime'=>'\DateTimeImmutable',
        'datetimetz'=>'\DateTimeImmutable','timestamp'=>'\DateTimeImmutable','timestamptz'=>'\DateTimeImmutable',
        /* json  */ 'json'=>'array','simple_json'=>'array','array'=>'array','simple_array'=>'array','set'=>'array',
        /* misc  */ 'uuid'=>'string','binary'=>'string','enum'=>'string','geometry'=>'string','point'=>'string',
        'linestring'=>'string','polygon'=>'string','ipAddress'=>'string','macAddress'=>'string',
    ];

    /* helpers */
    private array $completions  = [];
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
            readline_completion_function([$this,'readlineComplete']);
        }

        $this->inflector = InflectorFactory::create()->build();

        /* 1️⃣  entity name */
        $name = $_SERVER['argv'][2] ?? $this->ask('Enter entity name (e.g. User)');
        if (!preg_match('/^[A-Z][A-Za-z0-9]+$/', $name)) {
            return $this->fail('Invalid class name – must start with uppercase.');
        }

        /* 2️⃣  ensure file exists */
        $dir  = base_path('app/Entity');
        $file = "$dir/$name.php";
        @mkdir($dir, 0755, true);
        if (!is_file($file)) {
            $this->createStub($name, $file);
            $this->info("✅  Created stub $file");
            $this->createRepoStub($name);
        }

        /* 3️⃣  scan existing props */
        $src = file_get_contents($file);
        preg_match_all('/#\[Field.+\$([A-Za-z0-9_]+)/',                $src, $m); $existingFields = $m[1] ?? [];
        preg_match_all('/#\[(OneToOne|OneToMany|ManyToOne|ManyToMany).+\$([A-Za-z0-9_]+)/', $src, $m);
        $existingRels = $m[2] ?? [];

        $newFields = $newRels = [];

        /* 4️⃣  wizard */
        menu:
        $this->info("\n===== Make Entity: $name =====");
        $this->line("[1] Add field");
        $this->line("[2] Add relationship");
        $this->line("[3] Finish & save");
        switch ($this->ask('Choose option 1-3')) {
            case '1': $this->wizardField($existingFields, $newFields); goto menu;
            case '2': $this->wizardRelation($existingRels, $newRels, $name);  goto menu;
            case '3': break;
            default : $this->error('Enter 1, 2 or 3');                 goto menu;
        }
        if (!$newFields && !$newRels) { $this->info('No changes.'); return self::SUCCESS; }

        /* 5️⃣  build fragments */
        $props = $ctors = $methods = [];
        foreach ($newFields as $p => $t) {
            $this->emitField($p, $t, $props, $methods);
        }
        foreach ($newRels as $p => $m) {
            $this->emitRelation(
                $p, $m['attr'], $m['target'],
                $props, $ctors, $methods,
                $m['other_prop'] ?? null,
                $m['joinTable'] ?? null,
                $this->hasProperty($code ?? '', $p)
            );
        }

        /* 6️⃣  inject into file */
        $code = file_get_contents($file);
        if (preg_match('/^(?<head>.*?\{)(?<body>.*)(?<tail>\})\s*$/s', $code, $m)) {
            $body = $m['body'];

            // Insert exactly one blank line + your props + one trailing newline
            if (!empty($props)) {
                $propsBlock = "\n" . implode("\n", $props) . "\n";
                $body = preg_replace(
                    '/(?=\s*public function __construct\(\))/m',
                    $propsBlock,
                    $body,
                    1
                );
            }

            // Then patch constructor
            if (!empty($ctors)) {
                $body = preg_replace(
                    '/(public function __construct\(\)\s*\{)/',
                    "$1\n" . implode("\n", $ctors),
                    $body
                );
            }

            // Finally append your methods
            if (!empty($methods)) {
                $body .= "\n" . implode("\n", $methods) . "\n";
            }

            file_put_contents($file, $m['head'] . $body . $m['tail'] . "\n");
        }
        $this->info("✅  Updated $file");

        /* 7️⃣  inverse patch */
        $this->applyInverseQueue();
        return self::SUCCESS;
    }

    /**
     * Prompt the user for a field name and type.
     *
     * @param array $existing Existing properties to check against.
     * @param array &$out      Output array to store the new property.
     */
    private function wizardField(array $existing, array &$out): void
    {
        $prop = $this->ask('  Field name'); if ($prop===''||isset($existing[$prop])||isset($out[$prop])) return;
        if (!preg_match('/^[a-z][A-Za-z0-9_]*$/',$prop)) { $this->error('Invalid.'); return; }

        $type = $this->chooseOption('field', $this->fieldTypes);
        $out[$prop]=$type; $this->info("  ➕  $prop:$type added.");
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
        array &$out,
        string $selfClass
    ): void
    {
        $kind = $this->chooseOption('relation', array_keys($this->relTypes));
        $attr = $this->relTypes[$kind];

        /* target entity */
        $this->completions = array_map(fn($f)=>basename($f,'.php'),
            glob(base_path('app/Entity').'/*.php'));
        $target = $this->ask('  Target entity'); if ($target==='') return;

        $short = str_contains($target,'\\') ? substr($target,strrpos($target,'\\')+1) : $target;
        $fqcn  = str_contains($target,'\\') ? $target : "App\\Entity\\$target";

        if (in_array($attr, ['OneToMany', 'ManyToMany'], true)) {
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
        if ($prop===''||isset($existing[$prop])||isset($out[$prop])) return;

        /* inverse side? */
        $inverseProp = null;
        if (strtolower($this->ask('  Generate inverse side in target? [y/N]')) === 'y') {
            $invAttr = $this->inverseMap[$attr];
            $base = lcfirst($selfClass);
            if (in_array($invAttr, ['OneToMany','ManyToMany'], true)) {
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
                $attr === 'OneToOne'
            );
        }

        $out[$prop] = [
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
        // Map DB type → PHP type (fallback to raw $db)
        $type  = $this->phpTypeMap[$db] ?? $db;
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
        bool $skipProperty = false
    ): void {
        // short class name (“Project” from “App\Entity\Project”)
        $short = substr($target, strrpos($target, '\\') + 1);
        $Stud  = ucfirst($prop);
        $many  = in_array($attr, ['OneToMany', 'ManyToMany'], true);

        /* ───── build attribute arguments ───── */
        $args = ["targetEntity: {$short}::class"];

        /* ①  special-case: inverse side of One-to-One  */
        if ($attr === 'OneToOne' && $skipProperty && $otherProp) {
            // This is the *inverse* side – it should point back with mappedBy
            $args[] = "mappedBy: '{$otherProp}'";
        }
        /* ②  normal mapping rules for everything else */
        elseif ($otherProp) {
            if (in_array($attr, ['OneToMany', 'ManyToMany'], true)) {
                $args[] = "mappedBy: '{$otherProp}'";
            } elseif (in_array($attr, ['ManyToOne', 'OneToOne'], true)) {
                $args[] = "inversedBy: '{$otherProp}'";
            }
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
        string $fqcn,
        string $prop,
        string $attr,
        string $target,
        ?string $otherProp = null,
        bool $isInverseOneToOne = false
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
        foreach ($this->inverseQueue as $fqcn=>$defs) {
            $file = base_path('app/Entity/'.substr($fqcn,strrpos($fqcn,'\\')+1).'.php');
            if (!is_file($file)) $this->createStub(substr($fqcn,strrpos($fqcn,'\\')+1),$file);

            $code = file_get_contents($file);
            if (!preg_match('/^(?<head>.*?\{)(?<body>.*)(?<tail>\})\s*$/s',$code,$m)) continue;

            $props=$ctor=$meth=[];
            foreach ($defs as $d) {
                $this->emitRelation(
                    $d['prop'],$d['attr'],$d['target'],
                    $props,$ctor,$meth,
                    $d['other_prop'],
                    $d['joinTable'] ?? null,
                    $d['inverse_o2o'] ?? false
                );
            }

            // start with the existing body
            $body = $m['body'];

            // inject props just before the constructor
            if(!empty($props)){
                $propsBlock = "\n" . implode("\n", $props) . "\n";
                $body = preg_replace(
                    '/(?=\s*public function __construct\(\))/m',
                    $propsBlock,
                    $body,
                    1
                );
            }

            $body = preg_replace('/(public function __construct\(\)\s*\{)/',
                "$1\n".implode("\n",$ctor),$body,1,$ok);
            if(!$ok && $ctor){
                $body = "    public function __construct()\n    {\n".
                    implode("\n",$ctor)."\n    }\n\n".$body;
            }
            $body .= "\n".implode("\n",$meth)."\n";

            file_put_contents($file,$m['head'].$body.$m['tail']."\n");
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
    { return function_exists('readline') ? trim(readline("$q ")) : trim(fgets(STDIN)); }

    /**
     * Displays a message to the user.
     *
     * @param string $in
     * @param int $i
     * @return array
     */
    public function readlineComplete(string $in,int $i): array
    { return array_filter($this->completions,fn($o)=>str_starts_with($o,$in)); }

    /**
     * Displays an error message to the user.
     *
     * @param string $kind
     * @param array $opts
     * @return string
     */
    private function chooseOption(string $kind,array $opts): string
    {
        foreach ($opts as $i=>$o) $this->line(sprintf("  [%2d] %s",$i+1,$o));
        $this->completions=$opts;
        while(true){
            $sel=$this->ask("Select $kind");
            if(ctype_digit($sel)&&isset($opts[$sel-1])) return $opts[$sel-1];
            if(in_array($sel,$opts,true)) return $sel;
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
    private function createStub(string $name,string $file): void
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
    private function fail(string $msg): int { $this->error($msg); return self::FAILURE; }

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

    /** Does $body already contain `function <name>(` ? */
    private function hasMethod(string $body, string $name): bool
    {
        return (bool) preg_match('/function\s+' . preg_quote($name, '/') . '\s*\(/', $body);
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