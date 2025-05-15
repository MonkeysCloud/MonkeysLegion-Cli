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

    /** offered for readline completion */
    private array $completions = [];

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

        /* 5️⃣  Inject code --------------------------------------------------- */
        $lines=file($file,FILE_IGNORE_NEW_LINES); $out=[]; $last=array_key_last($lines);
        foreach ($lines as $i=>$ln) {
            if ($i===$last) {
                foreach ($newFields as $p=>$t){
                    $out[]="    #[Field(type: '{$t}')]";
                    $out[]="    private {$t} \${$p};"; $out[]="";
                }
                foreach ($newRels as $p=>$meta){
                    $att=$meta['attr']; $tar=$meta['target'];
                    $phpType=in_array($att,['OneToMany','ManyToMany'])?"{$tar}[]":$tar;
                    $out[]="    #[{$att}(targetEntity: {$tar}::class)]";
                    $out[]="    private {$phpType} \${$p};"; $out[]="";
                }
            }
            $out[]=$ln;
        }
        file_put_contents($file,implode("\n",$out));
        $this->info("✅  Updated   {$file}");
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

    private function wizardRelation(array $existing,array &$new): void
    {
        // ➊ choose relation kind
        $kind = $this->chooseOption('relation',array_keys($this->relTypes));
        $attr = $this->relTypes[$kind];

        // ➋ detect entities for TAB completion
        $entityDir = base_path('app/Entity');
        $entities  = array_map(fn($f)=>basename($f,'.php'),glob($entityDir.'/*.php'));
        $this->completions = $entities;

        // ➌ target FQCN (fallback to App\Entity\{X} if short name used)
        $target = $this->ask('  Target entity class (e.g. Post or App\\Entity\\Post)');
        if ($target==='') { $this->error('  Cancelled.'); return; }
        if (!str_contains($target,'\\')) $target = "App\\Entity\\{$target}";
        if(!preg_match('/^[A-Z][A-Za-z0-9_\\\\]+$/',$target)){
            $this->error('  Invalid class name.'); return;
        }

        // ➍ suggest property name
        $suggest = lcfirst(basename(str_replace('\\','/',$target)));
        if (in_array($attr,['OneToMany','ManyToMany'])) $suggest .= 's';   // plural
        $this->completions = [$suggest];
        $prop = $this->ask("  Property name [{$suggest}]");
        $prop = $prop!=='' ? $prop : $suggest;

        if(isset($existing[$prop])||isset($new[$prop])){ $this->error("  {$prop} exists."); return; }
        if(!preg_match('/^[a-z][A-Za-z0-9_]*$/',$prop)){ $this->error('  Invalid name.'); return; }

        $new[$prop] = ['attr'=>$attr,'target'=>$target];
        $this->info("  ➕  {$prop}:{$kind} ➔ {$target}");
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