<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Generate a custom CLI command with PHP 8.4 patterns.
 *
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
        $withHooks   = $this->confirm('Include property hooks example?', false);

        $body = $withHooks
            ? $this->buildHooksStub($name, $signature, $description)
            : $this->buildSimpleStub($name, $signature, $description);

        return $this->writeStub('app/Command', $name, $body);
    }

    private function buildSimpleStub(string $name, string $signature, string $description): string
    {
        return <<<PHP
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
    }

    private function buildHooksStub(string $name, string $signature, string $description): string
    {
        return <<<PHP
            <?php
            declare(strict_types=1);

            namespace App\\Command;

            use MonkeysLegion\\Cli\\Console\\Attributes\\Command as CommandAttr;
            use MonkeysLegion\\Cli\\Console\\Command;

            /**
             * {$description}
             *
             * Demonstrates PHP 8.4 property hooks and asymmetric visibility.
             */
            #[CommandAttr('{$signature}', '{$description}')]
            final class {$name} extends Command
            {
                /** Processed item count — clamped to non-negative via set hook. */
                private int \$processed = 0 {
                    set(int \$value) {
                        \$this->processed = max(0, \$value);
                    }
                }

                /** Computed success rate via get hook. */
                public float \$successRate {
                    get => \$this->processed > 0
                        ? \$this->succeeded / \$this->processed
                        : 0.0;
                }

                private int \$succeeded = 0;

                protected function handle(): int
                {
                    \$items = ['alpha', 'beta', 'gamma', 'delta'];

                    \$this->progressStart(count(\$items), 'Processing');

                    foreach (\$items as \$item) {
                        // TODO: process \$item
                        \$this->processed++;
                        \$this->succeeded++;
                        \$this->progressAdvance();
                    }

                    \$this->progressFinish();

                    \$this->info(sprintf(
                        '✅ Processed %d items (%.0f%% success rate)',
                        \$this->processed,
                        \$this->successRate * 100,
                    ));

                    return self::SUCCESS;
                }
            }

            PHP;
    }
}
