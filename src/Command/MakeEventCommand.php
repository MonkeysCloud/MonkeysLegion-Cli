<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('make:event', 'Generate a domain event class')]
final class MakeEventCommand extends Command
{
    use MakerHelpers;

    protected function handle(): int
    {
        $name = $this->argument(0) ?? $this->ask('Event name (e.g., UserRegistered):');

        if (trim($name) === '') {
            return $this->fail('Event name is required.');
        }

        $name = $this->toPascalCase($name);

        $stub = <<<PHP
            <?php
            declare(strict_types=1);

            namespace App\\Event;

            /**
             * {$name} domain event.
             */
            final readonly class {$name}
            {
                public function __construct(
                    public int|string \$entityId,
                    public array \$payload = [],
                    public \\DateTimeImmutable \$occurredAt = new \\DateTimeImmutable(),
                ) {}
            }

            PHP;

        return $this->writeStub('app/Event', $name, $stub);
    }
}
