<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

#[CommandAttr('make:entity', 'Generate a new Entity class with fields')]
final class MakeEntityCommand extends Command
{
    /** @var string[] Supported field types (Laravel & Doctrine‑like) */
    private array $types = [
        'string','char','text','mediumText','longText',
        'integer','tinyInt','smallInt','bigInt','unsignedBigInt',
        'decimal','float','boolean',
        'date','time','datetime','datetimetz','timestamp','timestamptz','year',
        'uuid','binary','json','simple_json','array','simple_array',
        'enum','set','geometry','point','linestring','polygon',
        'ipAddress','macAddress',
    ];

    protected function handle(): int
    {
        // 1) Ask for the entity (class) name
        $name = $this->ask('Enter entity name (e.g. User)');
        if (! preg_match('/^[A-Z][A-Za-z0-9]+$/', $name)) {
            $this->error('Invalid class name. Must start with uppercase and contain only letters/numbers.');
            return self::FAILURE;
        }

        // 2) Collect fields
        $fields = [];
        $this->info("\nAvailable field types:\n  " . implode(', ', $this->types) . "\n");
        $this->info("Now add fields. Leave name blank when done.\n");

        while (true) {
            $prop = $this->ask('Field name');
            if ($prop === '') {
                break;
            }
            if (! preg_match('/^[a-z][A-Za-z0-9_]*$/', $prop)) {
                $this->error('  ❌  Invalid property name.');
                continue;
            }

            $type = $this->chooseType();
            $fields[] = ['prop' => $prop, 'type' => $type];
            $this->info("  ➕  Added field \${$prop}:{$type}\n");
        }

        // 3) Build the class PHP
        $namespace = 'App\\Entity';
        $className = $name;
        $dir        = base_path('app/Entity');
        @mkdir($dir, 0755, true);
        $file = "{$dir}/{$className}.php";

        $stub = [];
        $stub[] = "<?php";
        $stub[] = "declare(strict_types=1);";
        $stub[] = "";
        $stub[] = "namespace {$namespace};";
        $stub[] = "";
        $stub[] = "use MonkeysLegion\\Entity\\Attributes\\Field;";
        $stub[] = "";
        $stub[] = "class {$className}";
        $stub[] = "{";

        // properties
        foreach ($fields as $f) {
            $stub[] = "    #[Field(type: '{$f['type']}')]";
            $stub[] = "    private {$f['type']} \${$f['prop']};";
            $stub[] = "";
        }

        // constructor
        $stub[] = "    public function __construct()";
        $stub[] = "    {";
        $stub[] = "        // initialize defaults if needed";
        $stub[] = "    }";
        $stub[] = "";

        // getters & setters
        foreach ($fields as $f) {
            $uc = ucfirst($f['prop']);
            // getter
            $stub[] = "    public function get{$uc}(): {$f['type']}";
            $stub[] = "    {";
            $stub[] = "        return \$this->{$f['prop']};";
            $stub[] = "    }";
            $stub[] = "";
            // setter
            $stub[] = "    public function set{$uc}({$f['type']} \${$f['prop']}): void";
            $stub[] = "    {";
            $stub[] = "        \$this->{$f['prop']} = \${$f['prop']};";
            $stub[] = "    }";
            $stub[] = "";
        }

        $stub[] = "}";

        file_put_contents($file, implode("\n", $stub));
        $this->info("✅  Created Entity: {$file}");

        return self::SUCCESS;
    }

    /**
     * Prompt the user, read a line from STDIN.
     */
    private function ask(string $prompt): string
    {
        echo $prompt . ': ';
        $line = fgets(STDIN);
        return $line !== false ? trim($line) : '';
    }

    /**
     * Let the user choose one of the supported types.
     */
    private function chooseType(): string
    {
        // print numbered list
        foreach ($this->types as $i => $type) {
            $this->line(sprintf("  [%2d] %s", $i + 1, $type));
        }

        while (true) {
            $input = $this->ask('Select type by number or name');
            // numeric?
            if (ctype_digit($input) && isset($this->types[(int)$input - 1])) {
                return $this->types[(int)$input - 1];
            }
            // name?
            if (in_array($input, $this->types, true)) {
                return $input;
            }
            $this->error("  ❌  Invalid type. Enter a number or one of: " . implode(', ', $this->types));
        }
    }
}