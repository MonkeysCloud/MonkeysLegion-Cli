<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('make:job', 'Generate a queue job class')]
final class MakeJobCommand extends Command
{
    use MakerHelpers;

    protected function handle(): int
    {
        $name = $this->argument(0) ?? $this->ask('Job name (e.g., ProcessPayment):');

        if (trim($name) === '') {
            return $this->fail('Job name is required.');
        }

        $name  = $this->ensureSuffix($this->toPascalCase($name), 'Job');
        $queue = $this->ask('Queue name [default]:') ?: 'default';

        $stub = <<<PHP
            <?php
            declare(strict_types=1);

            namespace App\\Job;

            /**
             * {$name} — Queue job.
             *
             * Queue: {$queue}
             */
            final class {$name}
            {
                /** Maximum retry attempts. */
                public int \$maxAttempts = 3;

                /** Timeout in seconds. */
                public int \$timeout = 60;

                /** Queue name. */
                public string \$queue = '{$queue}';

                public function __construct(
                    private readonly array \$payload = [],
                ) {}

                public function handle(): void
                {
                    // Process the job
                }

                public function failed(\\Throwable \$exception): void
                {
                    // Handle failure (e.g., notify, log)
                }
            }

            PHP;

        return $this->writeStub('app/Job', $name, $stub);
    }
}
