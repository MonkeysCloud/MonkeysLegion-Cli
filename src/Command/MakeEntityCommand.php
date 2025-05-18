<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

#[CommandAttr('make:entity', 'Generate or update an Entity class with fields & relationships')]
final class MakeEntityCommand extends Command
{
    /* ───────────────────────── Config ───────────────────────── */

    /** @var string[] supported DB scalar types shown in the wizard */
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
        /* text */    'string'=>'string','char'=>'string','text'=>'string',
        'mediumText'=>'string','longText'=>'string',

        /* numeric */ 'integer'=>'int','tinyInt'=>'int','smallInt'=>'int','bigInt'=>'int',
        'unsignedBigInt'=>'int','decimal'=>'float','float'=>'float','boolean'=>'bool','year'=>'int',

        /* dates  */  'date'=>'\DateTimeImmutable','time'=>'\DateTimeImmutable',
        'datetime'=>'\DateTimeImmutable','datetimetz'=>'\DateTimeImmutable',
        'timestamp'=>'\DateTimeImmutable','timestamptz'=>'\DateTimeImmutable',

        /* structured */ 'json'=>'array','simple_json'=>'array','array'=>'array',
        'simple_array'=>'array','set'=>'array',

        /* misc */ 'uuid'=>'string','binary'=>'string','enum'=>'string','geometry'=>'string',
        'point'=>'string','linestring'=>'string','polygon'=>'string',
        'ipAddress'=>'string','macAddress'=>'string',
    ];

    /* runtime helpers */
    private array $completions   = [];
    private array $inverseQueue  = [];

    /* ──────────────────────── Entry point ─────────────────────── */

    protected function handle(): int
    {
        /* enable readline completion if ext-readline present */
        if (function_exists('readline_completion_function')) {
            readline_completion_function([$this,'readlineComplete']);
        }

        /* 1. entity name */
        $name = $_SERVER['argv'][2] ?? $this->ask('Enter entity name (e.g. User)');
        if (!preg_match('/^[A-Z][A-Za-z0-9]+$/', $name)) {
            return $this->fail('Invalid class name – must start with uppercase letter.');
        }

        /* 2. ensure file exists */
        $dir  = base_path('app/Entity');
        $file = "{$dir}/{$name}.php";
        @mkdir($dir, 0755, true);
        if (!is_file($file)) {
            $this->createStub($name, $file);
            $this->info("✅  Created stub $file");
        }

        /* 3. scan current props/relations */
        $src = file_get_contents($file);
        preg_match_all('/#\[Field.+\$([A-Za-z0-9_]+)/', $src, $m);
        $existingFields = $m[1] ?? [];
        preg_match_all('/#\[(OneToOne|OneToMany|ManyToOne|ManyToMany).+\$([A-Za-z0-9_]+)/', $src, $m);
        $existingRels   = $m[2] ?? [];

        $newFields = $newRels = [];

        /* 4. interactive wizard */
        menu:
        $this->info("\n===== Make Entity: $name =====");
        $this->line("[1] Add field");
        $this->line("[2] Add relationship");
        $this->line("[3] Finish & save");
        switch ($this->ask('Choose option 1-3')) {
            case '1': $this->wizardField($existingFields, $newFields); goto menu;
            case '2': $this->wizardRelation($existingRels, $newRels);  goto menu;
            case '3': break;
            default : $this->error('Enter 1, 2 or 3');                  goto menu;
        }

        if (!$newFields && !$newRels) { $this->info('No changes.'); return self::SUCCESS; }

        /* 5. build fragments --------------------------------------------- */
        $propDefs = $ctorInits = $methodDefs = [];

        foreach ($newFields as $p => $t) {
            $this->emitField($p, $t, $propDefs, $methodDefs);
        }
        foreach ($newRels as $p => $meta) {
            $this->emitRelation($p, $meta['attr'], $meta['target'], $propDefs, $ctorInits, $methodDefs);
        }

        /* 6. inject into class ------------------------------------------- */
        $code = file_get_contents($file);
        if (preg_match('/^(?<head>.*?\{)(?<body>.*)(?<tail>\})\s*$/s', $code, $m)) {
            $body = "\n" . implode("\n", $propDefs) . $m['body'];

            /* constructor inits */
            $body = preg_replace(
                '/(public function __construct\(\)\s*\{)/',
                "$1\n" . implode("\n", $ctorInits),
                $body
            );

            /* append methods */
            $body .= "\n" . implode("\n", $methodDefs) . "\n";

            file_put_contents($file, $m['head'] . $body . $m['tail'] . "\n");
        }
        $this->info("✅  Updated $file");

        /* 7. patch inverse sides */
        $this->applyInverseQueue();

        return self::SUCCESS;
    }

    /* ─────────────────────── Wizards ─────────────────────── */

    private function wizardField(array $existing, array &$out): void
    {
        $prop = $this->ask('  Field name (blank to cancel)');
        if ($prop === '' || isset($existing[$prop]) || isset($out[$prop])) return;
        if (!preg_match('/^[a-z][A-Za-z0-9_]*$/', $prop)) { $this->error('Invalid name'); return; }

        $type      = $this->chooseOption('field', $this->fieldTypes);
        $out[$prop]= $type;
        $this->info("  ➕  $prop:$type added.");
    }

    private function wizardRelation(array $existing, array &$out): void
    {
        $kind = $this->chooseOption('relation', array_keys($this->relTypes));
        $attr = $this->relTypes[$kind];

        /* target entity */
        $entities = array_map(fn($f)=>basename($f,'.php'), glob(base_path('app/Entity').'/*.php'));
        $this->completions = $entities;

        $target = $this->ask('  Target entity (short or FQCN)');
        if ($target === '') return;
        $short  = str_contains($target,'\\') ? substr($target,strrpos($target,'\\')+1) : $target;
        $fqcn   = str_contains($target,'\\') ? $target : "App\\Entity\\$target";

        /* property name suggestion */
        $prop = $this->ask("  Property name [".($d = lcfirst($short).($attr==='OneToMany'||$attr==='ManyToMany'?'s':'')).']') ?: $d;
        if ($prop === '' || isset($existing[$prop]) || isset($out[$prop])) return;

        /* queue inverse creation */
        if (strtolower($this->ask('  Generate inverse side in target? [y/N]'))==='y') {
            $invAttr = $this->inverseMap[$attr];
            $invProp = $this->ask("  Inverse property in $short [".
                ($s = lcfirst($_SERVER['argv'][2] ?? 'self').($invAttr==='OneToMany'||$invAttr==='ManyToMany'?'s':'')).']') ?: $s;
            $this->queueInverse($fqcn, $invProp, $invAttr, "App\\Entity\\".$_SERVER['argv'][2]);
        }

        $out[$prop] = ['attr'=>$attr,'target'=>$fqcn];
        $this->info("  ➕  $prop:$kind ➔ $fqcn");
    }

    /* ─────────────────── Fragment emitters ─────────────────── */

    private function emitField(string $prop,string $db,array &$props,array &$methods): void
    {
        $type = $this->phpTypeMap[$db] ?? $db;
        $Stud = ucfirst($prop);

        $props[] = "    #[Field(type: '$db')]";
        $props[] = "    private {$type} \${$prop};";
        $props[] = "";

        $methods[] = "    public function get{$Stud}(): {$type}";
        $methods[] = "    { return \$this->{$prop}; }";
        $methods[] = "";

        $methods[] = "    public function set{$Stud}({$type} \${$prop}): self";
        $methods[] = "    { \$this->{$prop} = \${$prop}; return \$this; }";
        $methods[] = "";
    }

    /** central relation generator used by both sides */
    private function emitRelation(
        string $prop,string $attr,string $target,
        array &$props,array &$ctor,array &$methods
    ): void {
        $short = substr($target,strrpos($target,'\\')+1);
        $Stud  = ucfirst($prop);
        $many  = in_array($attr,['OneToMany','ManyToMany'],true);

        if ($many) {
            $props[] = "    /** @var {$short}[] */";
            $props[] = "    #[{$attr}(targetEntity: {$short}::class)]";
            $props[] = "    private array \${$prop};";
            $ctor [] = "        \$this->{$prop} = [];";
        } else {
            $props[] = "    #[{$attr}(targetEntity: {$short}::class)]";
            $props[] = "    private ?{$short} \${$prop} = null;";
        }
        $props[] = "";

        if ($many) {
            $methods[] = "    public function add{$short}({$short} \$item): self";
            $methods[] = "    { \$this->{$prop}[] = \$item; return \$this; }";
            $methods[] = "";

            $methods[] = "    public function remove{$short}({$short} \$item): self";
            $methods[] = "    { \$this->{$prop} = array_filter(";
            $methods[] = "            \$this->{$prop}, fn(\$i)=>\$i!==\$item);";
            $methods[] = "      return \$this; }";
            $methods[] = "";

            $methods[] = "    /** @return {$short}[] */";
            $methods[] = "    public function get{$Stud}(): array";
            $methods[] = "    { return \$this->{$prop}; }";
            $methods[] = "";
        } else {
            $methods[] = "    public function get{$Stud}(): ?{$short}";
            $methods[] = "    { return \$this->{$prop}; }";
            $methods[] = "";

            $methods[] = "    public function set{$Stud}(?{$short} \${$prop}): self";
            $methods[] = "    { \$this->{$prop} = \${$prop}; return \$this; }";
            $methods[] = "";

            $methods[] = "    public function remove{$Stud}(): self";
            $methods[] = "    { \$this->{$prop} = null; return \$this; }";
            $methods[] = "";
        }
    }

    /* ────────────── inverse-side queue & patching ────────────── */

    private function queueInverse(string $fqcn,string $prop,string $attr,string $target): void
    {   $this->inverseQueue[$fqcn][]=['prop'=>$prop,'attr'=>$attr,'target'=>$target]; }

    private function applyInverseQueue(): void
    {
        foreach ($this->inverseQueue as $fqcn=>$defs) {
            $file = base_path('app/Entity/'.substr($fqcn,strrpos($fqcn,'\\')+1).'.php');
            if (!is_file($file)) $this->createStub(substr($fqcn,strrpos($fqcn,'\\')+1),$file);

            $code = file_get_contents($file);
            if (!preg_match('/^(?<head>.*?\{)(?<body>.*)(?<tail>\})\s*$/s',$code,$m)) continue;

            $props=$ctor=$meth=[];
            foreach ($defs as $d) {
                $this->emitRelation($d['prop'],$d['attr'],$d['target'],$props,$ctor,$meth);
            }

            $body = "\n".implode("\n",$props).$m['body'];
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

    /* ────────────────────────── Helpers ───────────────────────── */

    private function ask(string $q): string
    { return function_exists('readline') ? trim(readline("$q ")) : trim(fgets(STDIN)); }

    public function readlineComplete(string $in,int $i): array
    { return array_filter($this->completions,fn($o)=>str_starts_with($o,$in)); }

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

    private function createStub(string $name,string $file): void
    {
        file_put_contents($file,<<<PHP
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

PHP);
    }

    private function fail(string $msg): int { $this->error($msg); return self::FAILURE; }
}