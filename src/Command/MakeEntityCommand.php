<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

#[CommandAttr('make:entity', 'Generate or update an Entity class with fields & relationships')]
final class MakeEntityCommand extends Command
{
    /* ─────────────────────────────────  Config  ───────────────────────── */
    private array $fieldTypes = [
        'string','char','text','mediumText','longText',
        'integer','tinyInt','smallInt','bigInt','unsignedBigInt',
        'decimal','float','boolean',
        'date','time','datetime','datetimetz','timestamp','timestamptz','year',
        'uuid','binary','json','simple_json','array','simple_array',
        'enum','set','geometry','point','linestring','polygon',
        'ipAddress','macAddress',
    ];

    /** cli-keyword   → attribute-class */
    private array $relTypes = [
        'oneToOne'   => 'OneToOne',
        'oneToMany'  => 'OneToMany',
        'manyToOne'  => 'ManyToOne',
        'manyToMany' => 'ManyToMany',
    ];

    /** owning-side attr  → inverse attr in target entity */
    private array $inverseMap = [
        'OneToOne'   => 'OneToOne',
        'ManyToOne'  => 'OneToMany',
        'OneToMany'  => 'ManyToOne',
        'ManyToMany' => 'ManyToMany',
    ];

    /**
     * db-type → PHP type for property & methods
     */
    private array $phpTypeMap = [
        // text types
        'string'         => 'string',
        'char'           => 'string',
        'text'           => 'string',
        'mediumText'     => 'string',
        'longText'       => 'string',

        // numeric types
        'integer'        => 'int',
        'tinyInt'        => 'int',
        'smallInt'       => 'int',
        'bigInt'         => 'int',
        'unsignedBigInt' => 'int',
        'decimal'        => 'float',
        'float'          => 'float',
        'boolean'        => 'bool',
        'year'           => 'int',

        // date/time
        'date'           => '\DateTimeImmutable',
        'time'           => '\DateTimeImmutable',
        'datetime'       => '\DateTimeImmutable',
        'datetimetz'     => '\DateTimeImmutable',
        'timestamp'      => '\DateTimeImmutable',
        'timestamptz'    => '\DateTimeImmutable',

        // special / structured
        'json'           => 'array',
        'simple_json'    => 'array',
        'array'          => 'array',
        'simple_array'   => 'array',
        'set'            => 'array',

        // miscellany
        'uuid'           => 'string',
        'binary'         => 'string',
        'enum'           => 'string',
        'geometry'       => 'string',
        'point'          => 'string',
        'linestring'     => 'string',
        'polygon'        => 'string',
        'ipAddress'      => 'string',
        'macAddress'     => 'string',
    ];

    /** offered for readline completion */
    private array $completions = [];

    /** queued inverse definitions: fqcn => [ [prop, attr, target], … ] */
    private array $inverseQueue = [];

    /* ───────────────────────────────  Entry point  ─────────────────────── */

    protected function handle(): int
    {
        // enable TAB completion if ext-readline exists
        if (function_exists('readline_completion_function')) {
            readline_completion_function([$this,'readlineComplete']);
        }

        /* 1️⃣  Ask for / validate Entity name -------------------------------- */
        $name = $_SERVER['argv'][2] ?? '';
        $name = $name !== '' ? $name : $this->ask('Enter entity name (e.g. User)');
        if (!preg_match('/^[A-Z][A-Za-z0-9]+$/',$name)) {
            return $this->fail('Invalid class name (must start with uppercase)');
        }

        /* 2️⃣  Ensure file exists (create stub if needed) -------------------- */
        $dir  = base_path('app/Entity');
        $file = "{$dir}/{$name}.php";
        @mkdir($dir,0755,true);

        if (!is_file($file)) {
            $this->createStub($name,$file);
            $this->info("✅  Created   {$file}");
        }

        /* 3️⃣  Parse existing props ----------------------------------------- */
        $src = file_get_contents($file);
        preg_match_all('/#\[Field[^\]]+]\s+private [^$]+\$([A-Za-z0-9_]+)/',$src,$m); $existingFields=$m[1]??[];
        preg_match_all('/#\[(OneToOne|OneToMany|ManyToOne|ManyToMany)[^\]]+]\s+private [^$]+\$([A-Za-z0-9_]+)/',$src,$m); $existingRels=$m[2]??[];

        $newFields=[]; $newRels=[];

        /* 4️⃣  Interactive menu --------------------------------------------- */
        loop:
        $this->info("\n===== Make Entity: {$name} =====");
        $this->line("[1] Add field");
        $this->line("[2] Add relationship");
        $this->line("[3] Finish & save");
        switch ($this->ask('Choose option 1-3')) {
            case '1':  $this->wizardField($existingFields,$newFields);  goto loop;
            case '2':  $this->wizardRelation($existingRels,$newRels);   goto loop;
            case '3':  break;
            default :  $this->error('Enter 1, 2 or 3');                 goto loop;
        }

        if (!$newFields && !$newRels) { $this->info('No changes.'); return self::SUCCESS; }

        // ─── 5️⃣ Build fragments ────────────────────────────────────────
        $propDefs   = [];
        $ctorInits  = [];
        $methodDefs = [];

        $camel  = fn(string $s)  => lcfirst(str_replace(' ', '', ucwords(str_replace('_',' ',$s))));
        $studly = fn(string $s) => ucfirst($camel($s));

        // ── scalar fields ─────────────────────────────────────────────
        foreach ($newFields as $prop => $type) {
            $this->buildFieldFragments($prop, $type, $propDefs, $ctorInits, $methodDefs);
        }

        // ── relationships ─────────────────────────────────────────────
        foreach ($newRels as $prop => $meta) {
            $this->buildRelationFragments($prop, $meta['attr'], $meta['target'], $propDefs, $ctorInits, $methodDefs);
        }

         // ─── 6️⃣ Inject into file ─────────────────────────────────────────
        $content = file_get_contents($file);
        if (preg_match('/^(?<header>.*?\{)(?<body>.*)(?<footer>\})\s*$/s', $content, $m)) {
            $header = $m['header'];
            $body   = $m['body'];
            $footer = $m['footer'];

            // 1) insert properties
            $body = "\n" . implode("\n", $propDefs) . $body;

            // 2) inject constructor inits
            $body = preg_replace_callback(
                '/(public function __construct\(\)\s*\{)/',
                function($c) use ($ctorInits) {
                    return $c[1] . "\n" . implode("\n", $ctorInits);
                },
                $body
            );

            // 3) append methods
            $body .= "\n" . implode("\n", $methodDefs) . "\n";

            // 4) rebuild and write back once
            file_put_contents($file, $header . $body . $footer . "\n");
        }

        $this->info("✅  Updated   {$file}");

        // ─── 7️⃣ Apply inverses ────────────────────────────────────────────
        $this->applyInverseQueue();

        return self::SUCCESS;
    }

    /* ───────────────────────────────  Wizards  ──────────────────────────── */

    private function wizardField(array $existing, array &$new): void
    {
        $prop=$this->ask('  Field name (blank to cancel)');
        if($prop==='') return;
        if(isset($existing[$prop])||isset($new[$prop])){ $this->error("  {$prop} exists."); return; }
        if(!preg_match('/^[a-z][A-Za-z0-9_]*$/',$prop)){ $this->error('  Invalid name.'); return; }

        $type=$this->chooseOption('field',$this->fieldTypes);
        $new[$prop]=$type;
        $this->info("  ➕  {$prop}:{$type} added.");
    }

    private function wizardRelation(array $existing, array &$new): void
    {
        /* ➊ choose relation kind */
        $kind = $this->chooseOption('relation', array_keys($this->relTypes));
        $attr = $this->relTypes[$kind];

        /* ➋ choose / autocomplete target entity */
        $entityDir = base_path('app/Entity');
        $entities  = array_map(fn($f)=>basename($f,'.php'), glob($entityDir.'/*.php'));
        $this->completions = $entities;

        $target = $this->ask('  Target entity class (short or FQCN)');
        if ($target==='') { $this->error('  Cancelled.'); return; }
        $short  = str_contains($target,'\\')
            ? substr($target, strrpos($target,'\\')+1)
            : $target;
        $targetFqcn = str_contains($target,'\\')
            ? $target
            : "App\\Entity\\{$target}";
        if (!preg_match('/^[A-Z][A-Za-z0-9_\\\\]+$/',$targetFqcn)) {
            $this->error('  Invalid class name.'); return;
        }

        /* ➌ suggest property name for THIS side */
        $suggest = lcfirst($short);
        if (in_array($attr,['OneToMany','ManyToMany'],true)) $suggest .= 's';
        $this->completions = [$suggest];
        $prop = $this->ask("  Property name [{$suggest}]");
        $prop = $prop!=='' ? $prop : $suggest;

        if(isset($existing[$prop])||isset($new[$prop])){ $this->error("  {$prop} exists."); return; }
        if(!preg_match('/^[a-z][A-Za-z0-9_]*$/',$prop)){ $this->error('  Invalid name.'); return; }

        /* ➍ inverse-side autogeneration? */
        $wantInverse = strtolower($this->ask('  Generate inverse side in target? [y/N]'))==='y';
        $inverseProp = null;
        if ($wantInverse) {
            $invAttr = $this->inverseMap[$attr];
            $invSuggest = lcfirst($name = $_SERVER['argv'][2] ?? 'self');
            if (in_array($invAttr,['OneToMany','ManyToMany'],true)) $invSuggest .= 's';
            $this->completions = [$invSuggest];
            $inverseProp = $this->ask("  Inverse property in {$short} [{$invSuggest}]");
            $inverseProp = $inverseProp!=='' ? $inverseProp : $invSuggest;
            $this->queueInverse(
                $targetFqcn,
                $inverseProp,
                $invAttr,
                "App\\Entity\\{$name}"
            );
        }

        $new[$prop] = ['attr'=>$attr,'target'=>$targetFqcn];
        $this->info("  ➕  {$prop}:{$kind} ➔ {$targetFqcn}");
    }

    /** store inverse data to be applied later */
    private function queueInverse(string $fqcn, string $prop, string $attr, string $targetFqcn): void
    {
        $this->inverseQueue[$fqcn][] = [
            'prop'   => $prop,
            'attr'   => $attr,
            'target' => $targetFqcn,
        ];
    }

    /** after we finish OUR file, call this to patch every queued target */
    private function applyInverseQueue(): void
    {
        foreach ($this->inverseQueue as $fqcn => $defs) {
            $path = base_path('app/Entity/'.substr($fqcn,strrpos($fqcn,'\\')+1).'.php');
            if (!is_file($path)) {
                // make a stub if missing
                $this->createStub(substr($fqcn,strrpos($fqcn,'\\')+1), $path);
            }
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            $out   = [];
            $last  = array_key_last($lines);

            foreach ($lines as $i=>$ln) {
                if ($i===$last) {
                    foreach ($defs as $d) {
                        $short = substr($d['target'], strrpos($d['target'],'\\')+1);
                        $phpT  = in_array($d['attr'],['OneToMany','ManyToMany']) ? "{$short}[]" : $short;
                        $out[] = "    #[{$d['attr']}(targetEntity: {$short}::class)]";
                        $out[] = "    private {$phpT} \$".$d['prop'].";";
                        $out[] = "";
                        // ─── Relation methods ─────────────────────────────────────
                        if (in_array($d['attr'], ['OneToMany','ManyToMany'], true)) {
                            // add
                            $out[] = "    public function add".ucfirst($d['prop'])."({$short} \$item): self";
                            $out[] = "    {";
                            $out[] = "      \$this->{$d['prop']}[] = \$item; return \$this";
                            $out[] = "    }";
                            $out[] = "";
                            // getter
                            $out[] = "    /** @return {$short}[] */";
                            $out[] = "    public function get".ucfirst($d['prop'])."(): array";
                            $out[] = "    {";
                            $out[] = "      return \$this->{$d['prop']};";
                            $out[] = "    }";
                            $out[] = "";
                        } else {
                            // getter
                            $out[] = "    public function get".ucfirst($d['prop'])."(): ?{$short}";
                            $out[] = "    {";
                            $out[] = "      return \$this->{$d['prop']};";
                            $out[] = "    }";
                            $out[] = "";
                            // setter
                            $out[] = "    public function set".ucfirst($d['prop'])."(?{$short} \${$d['prop']}): self";
                            $out[] = "    {";
                            $out[] = "      \$this->{$d['prop']} = \${$d['prop']}; return \$this;";
                            $out[] = "    }";
                            $out[] = "";
                        }
                    }
                }
                $out[] = $ln;
            }
            file_put_contents($path, implode("\n",$out));
            $this->info("    ↪  Patched inverse side in {$path}");
        }
    }

    /* ────────────────────────────  Helpers  ────────────────────────────── */

    private function ask(string $prompt): string
    {
        if(function_exists('readline')){
            $in=readline($prompt.' '); return $in!==false?trim($in):'';
        }
        echo $prompt.': '; return trim(fgets(STDIN)?:'');
    }

    public function readlineComplete(string $input,int $index): array
    {   return array_filter($this->completions,fn($o)=>str_starts_with($o,$input)); }

    private function chooseOption(string $kind,array $opts): string
    {
        $this->line("\nAvailable {$kind}s:");
        foreach($opts as $i=>$o) $this->line(sprintf("  [%2d] %s",$i+1,$o));
        $this->completions=$opts;
        while(true){
            $sel=$this->ask("Select {$kind}");
            if(ctype_digit($sel)&&isset($opts[(int)$sel-1])) return $opts[(int)$sel-1];
            if(in_array($sel,$opts,true)) return $sel;
            $this->error('  Invalid choice.');
        }
    }

    private function createStub(string $name,string $file): void
    {
        $code=<<<PHP
<?php
declare(strict_types=1);

namespace App\Entity;

use MonkeysLegion\Entity\Attributes\Field;
use MonkeysLegion\Entity\Attributes\OneToOne;
use MonkeysLegion\Entity\Attributes\OneToMany;
use MonkeysLegion\Entity\Attributes\ManyToOne;
use MonkeysLegion\Entity\Attributes\ManyToMany;

class {$name}
{
    public function __construct()
    {
    }
}

PHP;
        file_put_contents($file,$code);
    }

    private function fail(string $msg): int { $this->error($msg); return self::FAILURE; }

    /**
     * Generate property-, ctor-init- and method fragments for a scalar field.
     */
    private function buildFieldFragments(
        string $prop,
        string $dbType,
        array  &$propDefs,
        array  &$ctorInits,
        array  &$methodDefs
    ): void {
        // Map DB type (e.g. "json") → PHP type ("array"), default to given type
        $phpType = $this->phpTypeMap[$dbType] ?? $dbType;

        // Studly-case version of the property name for method names
        $Stud = ucfirst(lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $prop)))));

        /* ------------------------------------------------- property & attribute */
        $propDefs[] = "    #[Field(type: '{$dbType}')]";
        $propDefs[] = "    private {$phpType} \${$prop};";
        $propDefs[] = "";

        /* ----------------------------------------------------------- get + set */
        $methodDefs[] = "    public function get{$Stud}(): {$phpType}";
        $methodDefs[] = "    {";
        $methodDefs[] = "        return \$this->{$prop};";
        $methodDefs[] = "    }";
        $methodDefs[] = "";

        $methodDefs[] = "    public function set{$Stud}({$phpType} \${$prop}): self";
        $methodDefs[] = "    {";
        $methodDefs[] = "        \$this->{$prop} = \${$prop};";
        $methodDefs[] = "        return \$this;";
        $methodDefs[] = "    }";
        $methodDefs[] = "";
    }

    /**
     * Generate fragments for a relation property + its methods.
     */
    private function buildRelationFragments(
        string $prop,
        string $attr,
        string $full,
        array  &$propDefs,
        array  &$ctorInits,
        array  &$methodDefs
    ): void {
        $short   = substr($full, strrpos($full, '\\') + 1);                // "Project"
        $Stud    = ucfirst(lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $prop)))));
        $isMany  = in_array($attr, ['OneToMany', 'ManyToMany'], true);

        /* ───────────── property & attribute ───────────── */
        if ($isMany) {
            $propDefs[] = "    /** @var {$short}[] */";
            $propDefs[] = "    #[{$attr}(targetEntity: {$short}::class)]";
            $propDefs[] = "    private array \${$prop};";
            $ctorInits[] = "        \$this->{$prop} = [];";
        } else {
            $propDefs[] = "    #[{$attr}(targetEntity: {$short}::class)]";
            $propDefs[] = "    private ?{$short} \${$prop} = null;";
        }
        $propDefs[] = "";

        /* ───────────── methods ───────────── */
        if ($isMany) {
            /* add */
            $methodDefs[] = "    public function add{$short}({$short} \$item): self";
            $methodDefs[] = "    {";
            $methodDefs[] = "        \$this->{$prop}[] = \$item;";
            $methodDefs[] = "        return \$this;";
            $methodDefs[] = "    }";
            $methodDefs[] = "";

            /* remove */
            $methodDefs[] = "    public function remove{$short}({$short} \$item): self";
            $methodDefs[] = "    {";
            $methodDefs[] = "        \$this->{$prop} = array_filter(";
            $methodDefs[] = "            \$this->{$prop}, fn(\$i) => \$i !== \$item";
            $methodDefs[] = "        );";
            $methodDefs[] = "        return \$this;";
            $methodDefs[] = "    }";
            $methodDefs[] = "";

            /* getter */
            $methodDefs[] = "    /** @return {$short}[] */";
            $methodDefs[] = "    public function get{$Stud}(): array";
            $methodDefs[] = "    {";
            $methodDefs[] = "        return \$this->{$prop};";
            $methodDefs[] = "    }";
            $methodDefs[] = "";
        } else {
            /* getter */
            $methodDefs[] = "    public function get{$Stud}(): ?{$short}";
            $methodDefs[] = "    {";
            $methodDefs[] = "        return \$this->{$prop};";
            $methodDefs[] = "    }";
            $methodDefs[] = "";

            /* setter */
            $methodDefs[] = "    public function set{$Stud}(?{$short} \${$prop}): self";
            $methodDefs[] = "    {";
            $methodDefs[] = "        \$this->{$prop} = \${$prop};";
            $methodDefs[] = "        return \$this;";
            $methodDefs[] = "    }";
            $methodDefs[] = "";

            /* unset */
            $methodDefs[] = "    public function remove{$Stud}(): self";
            $methodDefs[] = "    {";
            $methodDefs[] = "        \$this->{$prop} = null;";
            $methodDefs[] = "        return \$this;";
            $methodDefs[] = "    }";
            $methodDefs[] = "";
        }
    }
}