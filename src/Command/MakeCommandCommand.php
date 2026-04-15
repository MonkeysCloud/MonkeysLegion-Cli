<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('make:command', 'Generate a custom CLI command')]
final class MakeCommandCommand extends Command
{
    use MakerHelpers;

    protected function handle(): int
    {
        $name = $this->argument(0) ?? $this->ask('Command class name (e.g., ImportDataCommand):');

        if (trim($name) === '') {
            return $this->fail('Command name is required.');
        }

        $name      = $this->ensureSuffix($this->toPascalCase($name), 'Command');
        $signature = $this->ask('Signature (e.g., import:data):');

        if (trim($signature) === '') {
            $signature = $this->toSnakeCase($this->removeSuffix($name, 'Command'));
            $signature = str_replace('_', ':', $signature);
        }

        $description = $this->ask('Description:') ?: 'Custom command';

        $stub = <<<PHP
            <?php
            declare(strict_types=1);

            namespace App\\Command;

            use MonkeysLegion\\Cli\\Console\\Attributes\\Command as CommandAttr;
            use MonkeysLegion\\Cli\\Console\\Command;

            #[CommandAttr('{$signature}', '{$description}')]
            final class {$name} extends Command
            {
                protected function handle(): int
                {
                    \$this->info('Hello from {$signature}!');

                    return self::SUCCESS;
                }
            }

            PHP;

        return $this->writeStub('app/Command', $name, $stub);
    }
}
