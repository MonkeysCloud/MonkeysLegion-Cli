<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

#[CommandAttr('make:entity', 'Generate or update an Entity class with fields & relationships')]
final class MakeEntityCommand extends Command
{
    /** @var string[] Supported field types */
    private array $fieldTypes = [
        'string','char','text','mediumText','longText',
        'integer','tinyInt','smallInt','bigInt','unsignedBigInt',
        'decimal','float','boolean',
        'date','time','datetime','datetimetz','timestamp','timestamptz','year',
        'uuid','binary','json','simple_json','array','simple_array',
        'enum','set','geometry','point','linestring','polygon',
        'ipAddress','macAddress',
    ];

    /** @var string[] Supported relation types */
    private array $relTypes = [
        'oneToOne'   => 'OneToOne',
        'oneToMany'  => 'OneToMany',
        'manyToOne'  => 'ManyToOne',
        'manyToMany' => 'ManyToMany',
    ];

    protected function handle(): int
    {
        // 1) fetch the entity name from argv[2] if provided
        $argv = $_SERVER['argv'] ?? [];
        $name = $argv[2] ?? '';

        if ($name === '') {
            $name = $this->ask('Enter entity name (e.g. User)');
        } else {
            $this->info("Entity name: {$name}");
        }

        if (! preg_match('/^[A-Z][A-Za-z0-9]+$/', $name)) {
            $this->error('Invalid class name. Must start with uppercase and contain only letters/numbers.');
            return self::FAILURE;
        }

        // 2) Prepare file path
        $dir  = base_path('app/Entity');
        $file = "{$dir}/{$name}.php";
        @mkdir($dir, 0755, true);

        // 3) If it doesn’t exist, create stub
        if (! is_file($file)) {
            $this->createStub($name, $file);
            $this->info("✅  Created new Entity stub: {$file}");
        }

        // 4) Scan existing fields & relations
        $src = file_get_contents($file);
        preg_match_all(
            '/\#\[Field\([^\)]*\)\].+\$([A-Za-z0-9_]+);/m',
            $src, $fm, PREG_SET_ORDER
        );
        preg_match_all(
            '/\#\[(OneToOne|OneToMany|ManyToOne|ManyToMany)[^\]]*\].+\$([A-Za-z0-9_]+);/m',
            $src, $rm, PREG_SET_ORDER
        );

        $existingFields = array_map(fn($m) => $m[1], $fm);
        $existingRels   = array_map(fn($m) => $m[2], $rm);

        // 5) Ask to add new fields
        $newFields = [];
        $this->info("\nAvailable field types: " . implode(', ', $this->fieldTypes));
        $this->info("Add fields (leave blank name to finish):");
        while (true) {
            $prop = $this->ask(' Field name');
            if ($prop === '') break;

            if (in_array($prop, $existingFields, true) || isset($newFields[$prop])) {
                $this->error(" \${$prop} already defined"); continue;
            }
            if (! preg_match('/^[a-z][A-Za-z0-9_]*$/', $prop)) {
                $this->error(" Invalid name"); continue;
            }

            $type = $this->chooseOption('field', $this->fieldTypes);
            $newFields[$prop] = $type;
            $this->info("  ➕  Added field \${$prop}:{$type}");
        }

        // 6) Ask to add new relations
        $newRels = [];
        $this->info("\nAvailable relation types: " . implode(', ', array_keys($this->relTypes)));
        $this->info("Add relationships (leave blank name to finish):");
        while (true) {
            $prop = $this->ask(' Relation property name');
            if ($prop === '') break;

            if (in_array($prop, $existingRels, true) || isset($newRels[$prop])) {
                $this->error(" \${$prop} already defined"); continue;
            }
            if (! preg_match('/^[a-z][A-Za-z0-9_]*$/', $prop)) {
                $this->error(" Invalid name"); continue;
            }

            $rtypeKey = $this->chooseOption('relation', array_keys($this->relTypes));
            $class    = $this->relTypes[$rtypeKey];
            $target   = $this->ask(' Target Entity FQCN (e.g. App\\Entity\\Post)');
            if (! preg_match('/^[A-Z][A-Za-z0-9_\\\\]+$/', $target)) {
                $this->error(" Invalid class name"); continue;
            }

            $newRels[$prop] = ['type' => $class, 'target' => $target];
            $this->info("  ➕  Added relation \${$prop}:{$class} ➔ {$target}");
        }

        // 7) If nothing new, exit
        if (empty($newFields) && empty($newRels)) {
            $this->info("No changes; exiting.");
            return self::SUCCESS;
        }

        // 8) Inject into the class before the final “}”
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $out   = [];

        foreach ($lines as $line) {
            if (trim($line) === '}') {
                // append new field definitions
                foreach ($newFields as $p => $t) {
                    $out[] = "    #[Field(type: '{$t}')]";
                    $out[] = "    private {$t} \${$p};";
                    $out[] = "";
                }
                // append new relation definitions
                foreach ($newRels as $p => $info) {
                    $cls     = $info['type'];
                    $tar     = $info['target'];
                    $phpType = in_array($cls, ['OneToMany','ManyToMany'])
                        ? "{$tar}[]"
                        : "{$tar}";
                    $out[] = "    #[{$cls}(targetEntity: {$tar}::class)]";
                    $out[] = "    private {$phpType} \${$p};";
                    $out[] = "";
                }
            }
            $out[] = $line;
        }

        file_put_contents($file, implode("\n", $out));
        $this->info("\n✅  Updated Entity: {$file}");
        return self::SUCCESS;
    }

    private function createStub(string $name, string $file): void
    {
        $stub = [
            "<?php",
            "declare(strict_types=1);",
            "",
            "namespace App\\Entity;",
            "",
            "use MonkeysLegion\\Entity\\Attributes\\Field;",
            "use MonkeysLegion\\Entity\\Attributes\\OneToOne;",
            "use MonkeysLegion\\Entity\\Attributes\\OneToMany;",
            "use MonkeysLegion\\Entity\\Attributes\\ManyToOne;",
            "use MonkeysLegion\\Entity\\Attributes\\ManyToMany;",
            "",
            "class {$name}",
            "{",
            "    public function __construct()",
            "    {",
            "    }",
            "",
            "}",
        ];
        file_put_contents($file, implode("\n", $stub));
    }

    private function ask(string $prompt): string
    {
        echo $prompt . ': ';
        return trim(fgets(STDIN) ?: '');
    }

    protected function line(string $msg): void
    {
        echo $msg . "\n";
    }

    private function chooseOption(string $kind, array $opts): string
    {
        foreach ($opts as $i => $opt) {
            $this->line(sprintf("  [%2d] %s", $i + 1, $opt));
        }
        while (true) {
            $sel = $this->ask("Select {$kind} by number or name");
            if (ctype_digit($sel) && isset($opts[(int)$sel - 1])) {
                return $opts[(int)$sel - 1];
            }
            if (in_array($sel, $opts, true)) {
                return $sel;
            }
            $this->error("Invalid {$kind}; try again.");
        }
    }
}