<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('make:listener', 'Generate an event listener class')]
final class MakeListenerCommand extends Command
{
    use MakerHelpers;

    protected function handle(): int
    {
        $name = $this->argument(0) ?? $this->ask('Listener name (e.g., SendWelcomeEmail):');

        if (trim($name) === '') {
            return $this->fail('Listener name is required.');
        }

        $name  = $this->ensureSuffix($this->toPascalCase($name), 'Listener');
        $event = $this->ask('Event class to listen for (e.g., UserRegistered):');

        $stub = <<<PHP
            <?php
            declare(strict_types=1);

            namespace App\\Listener;

            use App\\Event\\{$event};

            /**
             * {$name} — listens for {$event}.
             */
            final class {$name}
            {
                public function __invoke({$event} \$event): void
                {
                    // Handle the event
                }
            }

            PHP;

        return $this->writeStub('app/Listener', $name, $stub);
    }
}
