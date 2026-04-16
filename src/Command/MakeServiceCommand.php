<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * Generate a service class stub.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('make:service', 'Generate a service class')]
final class MakeServiceCommand extends Command
{
    use MakerHelpers;

    protected function handle(): int
    {
        $name = $this->argument(0) ?? $this->ask('Service name (e.g., PaymentService):');

        if (trim($name) === '') {
            $this->error('Service name is required.');

            return self::FAILURE;
        }

        $name      = $this->ensureSuffix($name, 'Service');
        $singleton = $this->confirm('Register as singleton?', true);

        $singletonAttr = $singleton ? "\nuse MonkeysLegion\\DI\\Attributes\\Singleton;\n" : '';
        $singletonLine = $singleton ? "#[Singleton]\n" : '';

        $stub = <<<PHP
            <?php
            declare(strict_types=1);

            namespace App\\Service;
            {$singletonAttr}
            use Psr\\Log\\LoggerInterface;

            /**
             * {$name}
             */
            {$singletonLine}final class {$name}
            {
                public function __construct(
                    private readonly LoggerInterface \$logger,
                ) {}
            }

            PHP;

        return $this->writeStub('app/Service', $name, $stub);
    }
}
