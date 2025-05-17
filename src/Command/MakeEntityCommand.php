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
            $Stud = $studly($prop);
            // property
            $propDefs[] = "    #[Field(type: '{$type}')]";
            $propDefs[] = "    private {$type} \${$prop};";
            $propDefs[] = "";

            // getter
            $methodDefs[] = "    public function get{$Stud}(): {$type}";
            $methodDefs[] = "    { return \$this->{$prop}; }";
            $methodDefs[] = "";

            // setter
            $methodDefs[] = "    public function set{$Stud}({$type} \${$prop}): self";
            $methodDefs[] = "    { \$this->{$prop} = \${$prop}; return \$this; }";
            $methodDefs[] = "";
        }

        // ── relationships ─────────────────────────────────────────────
        foreach ($newRels as $prop => $meta) {
            $attr  = $meta['attr'];
            $full  = $meta['target'];
            $short = substr($full, strrpos($full,'\\')+1);
            $Stud  = $studly($prop);
            $isMany = in_array($attr, ['OneToMany','ManyToMany'], true);

            // property
            $phpType = $isMany ? "{$short}[]" : $short;
            $propDefs[] = "    #[{$attr}(targetEntity: {$short}::class)]";
            $propDefs[] = "    private {$phpType} \${$prop};";
            $propDefs[] = "";

            // constructor init for collections
            if ($isMany) {
                $ctorInits[] = "        \$this->{$prop} = [];";
            }

            // methods
            if ($isMany) {
                // add
                $methodDefs[] = "    public function add{$short}({$short} \$item): self";
                $methodDefs[] = "    { \$this->{$prop}[] = \$item; return \$this; }";
                $methodDefs[] = "";
                // remove
                $methodDefs[] = "    public function remove{$short}({$short} \$item): self";
                $methodDefs[] = "    {";
                $methodDefs[] = "        \$this->{$prop} = array_filter(";
                $methodDefs[] = "            \$this->{$prop}, fn(\$i) => \$i !== \$item";
                $methodDefs[] = "        );";
                $methodDefs[] = "        return \$this;";
                $methodDefs[] = "    }";
                $methodDefs[] = "";
                // getter
                $methodDefs[] = "    /** @return {$short}[] */";
                $methodDefs[] = "    public function get{$Stud}(): array";
                $methodDefs[] = "    { return \$this->{$prop}; }";
                $methodDefs[] = "";
            } else {
                // single-side getter
                $methodDefs[] = "    public function get{$Stud}(): ?{$short}";
                $methodDefs[] = "    { return \$this->{$prop}; }";
                $methodDefs[] = "";
                // setter
                $methodDefs[] = "    public function set{$Stud}(?{$short} \${$prop}): self";
                $methodDefs[] = "    { \$this->{$prop} = \${$prop}; return \$this; }";
                $methodDefs[] = "";
                // unset
                $methodDefs[] = "    public function unset{$Stud}(): self";
                $methodDefs[] = "    { \$this->{$prop} = null; return \$this; }";
                $methodDefs[] = "";
            }
        }

        // ─── 6️⃣ Inject into file ─────────────────────────────────────────
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $out   = [];
        $lastLine = array_key_last($lines);
        $insertedProps = false;
        $initializedCtor = false;

        foreach ($lines as $i => $ln) {
            // after `class X` line ⇒ insert all props
            if (!$insertedProps && preg_match('/^class\s+\w+/', $ln)) {
                $out[] = $ln;
                $out   = array_merge($out, $propDefs);
                $insertedProps = true;
                continue;
            }

            // inside constructor ⇒ insert inits
            if ($insertedProps && !$initializedCtor && preg_match('/function __construct\(\)/', $ln)) {
                $out[] = $ln;                           // signature
                $out[] = $lines[$i+1];                  // the `{`
                array_splice($out, -1, 0, $ctorInits);  // inject inits just before `{`
                $initializedCtor = true;
                continue;
            }

            // just before final `}` ⇒ inject methods
            if ($i === $lastLine) {
                $out   = array_merge($out, $methodDefs);
                $out[] = $ln;
                continue;
            }

            // default: copy line
            $out[] = $ln;
        }

        file_put_contents($file, implode("\n", $out));
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
}