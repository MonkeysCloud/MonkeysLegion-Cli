<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('make:observer', 'Generate an entity lifecycle observer')]
final class MakeObserverCommand extends Command
{
    use MakerHelpers;

    protected function handle(): int
    {
        $name = $this->argument(0) ?? $this->ask('Observer name (e.g., UserObserver):');

        if (trim($name) === '') {
            return $this->fail('Observer name is required.');
        }

        $name   = $this->ensureSuffix($this->toPascalCase($name), 'Observer');
        $entity = $this->removeSuffix($name, 'Observer');

        $stub = <<<PHP
            <?php
            declare(strict_types=1);

            namespace App\\Observer;

            use App\\Entity\\{$entity};

            /**
             * {$name} — lifecycle observer for {$entity}.
             */
            final class {$name}
            {
                public function creating({$entity} \$entity): void
                {
                    // Before insert
                }

                public function created({$entity} \$entity): void
                {
                    // After insert
                }

                public function updating({$entity} \$entity): void
                {
                    // Before update
                }

                public function updated({$entity} \$entity): void
                {
                    // After update
                }

                public function deleting({$entity} \$entity): void
                {
                    // Before delete
                }

                public function deleted({$entity} \$entity): void
                {
                    // After delete
                }
            }

            PHP;

        return $this->writeStub('app/Observer', $name, $stub);
    }
}
