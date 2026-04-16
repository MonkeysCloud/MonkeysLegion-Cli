<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('make:factory', 'Generate an entity factory for testing')]
final class MakeFactoryCommand extends Command
{
    use MakerHelpers;

    protected function handle(): int
    {
        $name = $this->argument(0) ?? $this->ask('Factory name (e.g., UserFactory):');

        if (trim($name) === '') {
            return $this->fail('Factory name is required.');
        }

        $name   = $this->ensureSuffix($this->toPascalCase($name), 'Factory');
        $entity = $this->removeSuffix($name, 'Factory');

        $stub = <<<PHP
            <?php
            declare(strict_types=1);

            namespace App\\Factory;

            use App\\Entity\\{$entity};

            /**
             * {$name} — test data factory for {$entity}.
             */
            final class {$name}
            {
                /**
                 * Create a default entity instance.
                 *
                 * @param array<string, mixed> \$overrides
                 */
                public function make(array \$overrides = []): {$entity}
                {
                    \$entity = new {$entity}();

                    // Set default values
                    // \$entity->name = \$overrides['name'] ?? 'Test Name';

                    foreach (\$overrides as \$prop => \$value) {
                        if (property_exists(\$entity, \$prop)) {
                            \$entity->\$prop = \$value;
                        }
                    }

                    return \$entity;
                }

                /**
                 * Create multiple entity instances.
                 *
                 * @param array<string, mixed> \$overrides
                 * @return list<{$entity}>
                 */
                public function count(int \$count, array \$overrides = []): array
                {
                    return array_map(
                        fn() => \$this->make(\$overrides),
                        range(1, \$count),
                    );
                }
            }

            PHP;

        return $this->writeStub('app/Factory', $name, $stub);
    }
}
