<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

#[CommandAttr('make:entity', 'Generate or update an Entity class with fields & relationships')]
final class MakeEntityCommand extends Command
{
    /** @var string[] Supported scalar field types */
    private array $fieldTypes = [
        'string','char','text','mediumText','longText',
        'integer','tinyInt','smallInt','bigInt','unsignedBigInt',
        'decimal','float','boolean',
        'date','time','datetime','datetimetz','timestamp','timestamptz','year',
        'uuid','binary','json','simple_json','array','simple_array',
        'enum','set','geometry','point','linestring','polygon',
        'ipAddress','macAddress',
    ];

    /** @var array<string,string> CLI keyword → attribute class */
    private array $relTypes = [
        'oneToOne'   => 'OneToOne',
        'oneToMany'  => 'OneToMany',
        'manyToOne'  => 'ManyToOne',
        'manyToMany' => 'ManyToMany',
    ];

    /** @var string[] items offered for readline completion */
    private array $completions = [];

    /* --------------------------------------------------------------------- */
    /*  Entry-point                                                          */
    /* --------------------------------------------------------------------- */

    protected function handle(): int
    {
        // enable TAB completion if ext-readline is present
        if (function_exists('readline_completion_function')) {
            readline_completion_function([$this,'readlineComplete']);
        }

        /* --------  1) Entity class name  --------------------------------- */
        $name = $_SERVER['argv'][2] ?? '';
        $name = $name !== '' ? $name : $this->ask('Enter entity name (e.g. User)');
        if (!preg_match('/^[A-Z][A-Za-z0-9]+$/', $name)) {
            return $this->fail('Invalid class name (must start with uppercase)');
        }

        $dir  = base_path('app/Entity');
        $file = "{$dir}/{$name}.php";
        @mkdir($dir, 0755, true);

        if (!is_file($file)) {
            $this->createStub($name, $file);
            $this->info("✅  Created new stub: {$file}");
        }

        /* --------  2) Parse current file  -------------------------------- */
        $src = file_get_contents($file);
        preg_match_all('/#\[Field[^\]]+]\s+private [^$]+\$([A-Za-z0-9_]+)/',        $src,$m); $existingFields = $m[1] ?? [];
        preg_match_all('/#\[(OneToOne|OneToMany|ManyToOne|ManyToMany)[^\]]+]\s+private [^$]+\$([A-Za-z0-9_]+)/',$src,$m); $existingRels = $m[2] ?? [];

        $newFields = [];
        $newRels   = [];

        /* --------  3) Interactive menu  ---------------------------------- */
        menu:
        $this->info("\n===== Make Entity: {$name} =====");
        $this->line("[1] Add field");
        $this->line("[2] Add relationship");
        $this->line("[3] Finish & save\n");

        switch ($this->ask('Choose option 1-3')) {
            case '1':
                $this->addField($existingFields,$newFields);
                goto menu;

            case '2':
                $this->addRelation($existingRels,$newRels);
                goto menu;

            case '3':
                break;

            default:
                $this->error('Enter 1, 2 or 3'); goto menu;
        }

        if (!$newFields && !$newRels) {
            $this->info('No changes – exiting.'); return self::SUCCESS;
        }

        /* --------  4) Inject new code  ----------------------------------- */
        $lines    = file($file, FILE_IGNORE_NEW_LINES);
        $out      = [];

        /**
         * We insert **before** the last “}”, but must keep every original line
         * (previous logic overwrote earlier lines by re-assigning $out).
         */
        $lastLineIndex = array_key_last($lines);

        foreach ($lines as $idx => $line) {

            // ── when we reach the closing brace, first inject new defs ──
            if ($idx === $lastLineIndex) {

                // ▸ scalar fields
                foreach ($newFields as $prop => $type) {
                    $out[] = "    #[Field(type: '{$type}')]";
                    $out[] = "    private {$type} \${$prop};";
                    $out[] = "";                               // blank line for readability
                }

                // ▸ relationships
                foreach ($newRels as $prop => $meta) {
                    $attr    = $meta['attr'];           // OneToOne, …
                    $target  = $meta['target'];         // FQCN
                    $phpType = in_array($attr, ['OneToMany','ManyToMany'])
                        ? "{$target}[]"          // collection
                        : $target;               // single object

                    $out[] = "    #[{$attr}(targetEntity: {$target}::class)]";
                    $out[] = "    private {$phpType} \${$prop};";
                    $out[] = "";
                }
            }

            // always write the original line (incl. the final “}”)
            $out[] = $line;
        }

        /* --------  8) Write it back  ------------------------------------ */
        file_put_contents($file, implode("\n", $out));
        $this->info("✅  Updated {$file}");
        return self::SUCCESS;
    }

    /* --------------------------------------------------------------------- */
    /*  Helpers                                                              */
    /* --------------------------------------------------------------------- */

    private function addField(array $existing,array &$new): void
    {
        $prop = $this->ask('  Field name (blank to cancel)');
        if ($prop==='') return;

        if (isset($existing[$prop]) || isset($new[$prop])) {
            $this->error("  {$prop} already exists."); return;
        }
        if (!preg_match('/^[a-z][A-Za-z0-9_]*$/',$prop)) {
            $this->error("  Invalid name."); return;
        }
        $type = $this->chooseOption('field',$this->fieldTypes);
        $new[$prop]=$type;
        $this->info("  ➕  {$prop}:{$type} added.");
    }

    private function addRelation(array $existing,array &$new): void
    {
        $prop = $this->ask('  Relation property (blank to cancel)');
        if ($prop==='') return;

        if (isset($existing[$prop]) || isset($new[$prop])) {
            $this->error("  {$prop} already exists."); return;
        }
        if (!preg_match('/^[a-z][A-Za-z0-9_]*$/',$prop)) {
            $this->error("  Invalid name."); return;
        }
        $kind = $this->chooseOption('relation',array_keys($this->relTypes));
        $attr = $this->relTypes[$kind];
        $fqcn = $this->ask('  Target entity FQCN');
        if (!preg_match('/^[A-Z][A-Za-z0-9_\\\\]+$/',$fqcn)) {
            $this->error("  Invalid class."); return;
        }
        $new[$prop]=['attr'=>$attr,'target'=>$fqcn];
        $this->info("  ➕  {$prop}:{$kind} ➔ {$fqcn}");
    }

    /* Readline-aware ask() ************************************************ */

    private function ask(string $prompt): string
    {
        if (function_exists('readline')) {
            $line = readline($prompt.' ');
            return $line!==false ? trim($line) : '';
        }
        echo $prompt.': ';
        return trim(fgets(STDIN) ?: '');
    }

    /** Offer tab-completion for $this->completions */
    public function readlineComplete(string $input,int $index): array
    {
        return array_filter($this->completions, fn($opt)=>str_starts_with($opt,$input));
    }

    private function chooseOption(string $kind,array $opts): string
    {
        $this->line("\nAvailable {$kind} types:");
        foreach ($opts as $i=>$opt) $this->line(sprintf("  [%2d] %s",$i+1,$opt));

        $this->completions = $opts;                 // enable completion list
        while (true) {
            $sel = $this->ask("Select {$kind}");
            if (ctype_digit($sel) && isset($opts[(int)$sel-1])) return $opts[(int)$sel-1];
            if (in_array($sel,$opts,true))           return $sel;
            $this->error('  Invalid choice.');
        }
    }

    private function createStub(string $name,string $file): void
    {
        $code = <<<PHP
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

    private function fail(string $msg): int
    {
        $this->error($msg);
        return self::FAILURE;
    }
}