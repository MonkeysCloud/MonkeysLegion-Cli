<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

#[CommandAttr('make:entity', 'Generate or update an Entity class with fields & relationships')]
final class MakeEntityCommand extends Command
{
    private array $fieldTypes = [ /*… same as before …*/ ];
    private array $relTypes   = [
        'oneToOne'   => 'OneToOne',
        'oneToMany'  => 'OneToMany',
        'manyToOne'  => 'ManyToOne',
        'manyToMany' => 'ManyToMany',
    ];

    protected function handle(): int
    {
        // —– ask for class name —–
        $name = $this->ask('Enter entity name (e.g. User)');
        if (! preg_match('/^[A-Z][A-Za-z0-9]+$/', $name)) {
            $this->error('Invalid class name.');
            return self::FAILURE;
        }
        $dir  = base_path('app/Entity');
        $file = "{$dir}/{$name}.php";
        @mkdir($dir, 0755, true);

        // —– collect existing stub or create new —–
        if (! is_file($file)) {
            $this->createStub($name, $file);
        }

        // —– scan existing fields & rels —–
        $src = file_get_contents($file);
        preg_match_all('/\#\[Field\([^\)]*\)\].+?\$([A-Za-z0-9_]+);/',    $src, $fm, PREG_SET_ORDER);
        preg_match_all('/\#\[(OneToOne|OneToMany|ManyToOne|ManyToMany)[^\]]*\].+?\$([A-Za-z0-9_]+);/', $src, $rm, PREG_SET_ORDER);

        $existingFields = array_column($fm, 0, 1);
        $existingRels   = array_column($rm, 0, 2);

        // —– add new fields —–
        $newFields = [];
        $this->info("\nAvailable field types: ".implode(', ',$this->fieldTypes));
        $this->info("Add fields (empty name to finish):");
        while (true) {
            $prop = $this->ask(' Field name');
            if ($prop==='' ) break;
            if (isset($existingFields[$prop]) || isset($newFields[$prop])) {
                $this->error(" \${$prop} already defined"); continue;
            }
            if (! preg_match('/^[a-z][A-Za-z0-9_]*$/',$prop)) {
                $this->error(" Invalid name"); continue;
            }
            $type = $this->chooseOption('field',$this->fieldTypes);
            $newFields[$prop] = $type;
            $this->info(" + field \${$prop}:{$type}");
        }

        // —– add new relationships —–
        $newRels = [];
        $this->info("\nAvailable relation types: ".implode(', ',array_keys($this->relTypes)));
        $this->info("Add relationships (empty name to finish):");
        while (true) {
            $prop = $this->ask(' Relation property name');
            if ($prop==='') break;
            if (isset($existingRels[$prop]) || isset($newRels[$prop])) {
                $this->error(" \${$prop} already defined"); continue;
            }
            if (! preg_match('/^[a-z][A-Za-z0-9_]*$/',$prop)) {
                $this->error(" Invalid name"); continue;
            }
            $rtypeKey = $this->chooseOption('relation',array_keys($this->relTypes));
            $rtype    = $this->relTypes[$rtypeKey];
            $target   = $this->ask(' Target Entity (e.g. App\\Entity\\Post)');
            if (! preg_match('/^[A-Z][A-Za-z0-9_\\\\]+$/',$target)) {
                $this->error(" Invalid class name"); continue;
            }
            $newRels[$prop] = ['type'=>$rtype,'target'=>$target];
            $this->info(" + relation \${$prop}:{$rtype} ➔ {$target}");
        }

        // nothing to do?
        if (empty($newFields) && empty($newRels)) {
            $this->info("No changes; exiting.");
            return self::SUCCESS;
        }

        // —– inject into file before trailing “}” —–
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $out   = [];
        foreach ($lines as $line) {
            if (trim($line)==='}') {
                // fields
                foreach ($newFields as $p=>$t) {
                    $out[] = "    #[Field(type: '{$t}')]";
                    $out[] = "    private {$t} \${$p};";
                    $out[] = "";
                }
                // rels
                foreach ($newRels as $p=>$info) {
                    $class = $info['type'];
                    $tar   = $info['target'];
                    $out[] = "    #[{$class}(targetEntity: {$tar}::class)]";
                    // if one‐to‐many or many‐to‐many, use array; else single
                    $phpType = in_array($class,['OneToMany','ManyToMany'])
                        ? "{$tar}[]"
                        : "{$tar}";
                    $out[] = "    private {$phpType} \${$p};";
                    $out[] = "";
                }
            }
            $out[] = $line;
        }
        file_put_contents($file, implode("\n",$out));

        $this->info("\n✅  Updated Entity: {$file}");
        return self::SUCCESS;
    }

    private function createStub(string $name, string $file): void
    {
        $stub = [
            "<?php","declare(strict_types=1);","",
            "namespace App\\Entity;","",
            "use MonkeysLegion\\Entity\\Attributes\\Field;",
            "use MonkeysLegion\\Entity\\Attributes\\OneToOne;",
            "use MonkeysLegion\\Entity\\Attributes\\OneToMany;",
            "use MonkeysLegion\\Entity\\Attributes\\ManyToOne;",
            "use MonkeysLegion\\Entity\\Attributes\\ManyToMany;","",
            "class {$name}","{",
            "    public function __construct()","    {","    }","",
            "}",
        ];
        file_put_contents($file, implode("\n",$stub));
    }

    private function ask(string $prompt): string
    {
        echo $prompt.': ';
        return trim(fgets(STDIN) ?: '');
    }

    private function chooseOption(string $kind, array $opts): string
    {
        foreach ($opts as $i=>$o) {
            $this->line(sprintf("  [%2d] %s", $i+1, $o));
        }
        while (true) {
            $sel = $this->ask("Select {$kind} by number or name");
            if (ctype_digit($sel) && isset($opts[(int)$sel-1])) {
                return $opts[(int)$sel-1];
            }
            if (in_array($sel,$opts,true)) {
                return $sel;
            }
            $this->error(" Invalid {$kind}; try again.");
        }
    }
}