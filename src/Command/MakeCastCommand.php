<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * MonKeysLegion Framework — CLI Package
 *
 * Generate a custom entity caster class implementing CastInterface.
 *
 * Usage:
 *   php bin/ml make:cast MoneyCast
 *   php bin/ml make:cast EncryptedCast --force
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('make:cast', 'Generate an entity caster class')]
final class MakeCastCommand extends Command
{
    use MakerHelpers;

    protected function handle(): int
    {
        $name = $this->argument(0) ?? $this->ask('Cast name (e.g., MoneyCast):');

        if (trim($name) === '') {
            return $this->fail('Cast name is required.');
        }

        $name = $this->toPascalCase($name);

        // Ensure suffix "Cast" is optional — ask user
        if (!str_ends_with($name, 'Cast') && $this->confirm('Append "Cast" suffix?', true)) {
            $name .= 'Cast';
        }

        $stub = <<<PHP
            <?php
            declare(strict_types=1);

            namespace App\\Cast;

            use MonkeysLegion\\Entity\\Contracts\\CastInterface;

            /**
             * {$name} — custom entity caster.
             *
             * Apply to entity properties:
             *   #[Cast({$name}::class)]
             *   public mixed \$field;
             */
            final class {$name} implements CastInterface
            {
                /**
                 * Cast the value when reading from the database (hydration).
                 */
                public function get(mixed \$value, string \$attribute, object \$entity): mixed
                {
                    // TODO: Transform the database value.
                    // Example: return (float) \$value;
                    return \$value;
                }

                /**
                 * Cast the value when writing to the database (extraction).
                 */
                public function set(mixed \$value, string \$attribute, object \$entity): mixed
                {
                    // TODO: Transform the entity value for storage.
                    // Example: return number_format(\$value, 2, '.', '');
                    return \$value;
                }
            }

            PHP;

        return $this->writeStub('app/Cast', $name, $stub);
    }
}
