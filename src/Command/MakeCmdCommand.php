<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

#[CommandAttr('make:command', 'Generate a new CLI Command class stub')]
final class MakeCmdCommand extends Command
{
    use MakerHelpers;

    public function handle(): int
    {
        $input = $this->argument(0) ?? $this->ask('Enter command name (e.g. Hello)');
        $name  = preg_replace('/Command$/', '', $input) . 'Command';

        if (!preg_match('/^[A-Z][A-Za-z0-9]+Command$/', $name)) {
            return $this->fail('Invalid name: must be CamelCase ending with "Command".');
        }

        $dir  = base_path('app/Cli/Command');
        $file = "{$dir}/{$name}.php";
        @mkdir($dir, 0755, true);

        if (file_exists($file)) {
            $this->line("ℹ️  Command already exists: {$file}");
            return self::SUCCESS;
        }

        $stub = <<<PHP
<?php

declare(strict_types=1);

namespace App\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

#[CommandAttr('TODO:signature', 'TODO:description')]
final class {$name} extends Command
{
    protected function handle(): int
    {
        \$this->info('Hello from {$name}!');

        return self::SUCCESS;
    }
}
PHP;

        file_put_contents($file, $stub);
        $this->info("✅  Created command: {$file}");
        return self::SUCCESS;
    }
}
