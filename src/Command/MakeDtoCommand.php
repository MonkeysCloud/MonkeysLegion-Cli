<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * Generate a Data Transfer Object — unique to MonkeysLegion.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('make:dto', 'Generate a readonly DTO class (unique to ML!)')]
final class MakeDtoCommand extends Command
{
    use MakerHelpers;

    protected function handle(): int
    {
        $name = $this->argument(0) ?? $this->ask('DTO name (e.g., CreateUserDto):');

        if (trim($name) === '') {
            return $this->fail('DTO name is required.');
        }

        $name = $this->ensureSuffix($this->toPascalCase($name), 'Dto');

        // Ask for properties
        $props = [];
        $this->info('Enter properties (empty name to stop):');

        while (true) {
            $propName = $this->ask('  Property name:');

            if (trim($propName) === '') {
                break;
            }

            $propType = $this->choice('  Type?', ['string', 'int', 'float', 'bool', 'array', '?string', '?int'], 0);
            $props[]  = ['name' => $this->toCamelCase($propName), 'type' => $propType];
        }

        $propsBlock = '';

        foreach ($props as $prop) {
            $propsBlock .= "        public readonly {$prop['type']} \${$prop['name']},\n";
        }

        if ($propsBlock !== '') {
            $propsBlock = rtrim($propsBlock, ",\n") . ",\n";
        }

        $stub = <<<PHP
            <?php
            declare(strict_types=1);

            namespace App\\Dto;

            /**
             * {$name} — Data Transfer Object.
             */
            final readonly class {$name}
            {
                public function __construct(
            {$propsBlock}    ) {}

                /**
                 * Create from an associative array.
                 *
                 * @param array<string, mixed> \$data
                 */
                public static function fromArray(array \$data): self
                {
                    return new self(
                        // Map \$data keys to constructor args
                    );
                }

                /**
                 * Convert to array.
                 *
                 * @return array<string, mixed>
                 */
                public function toArray(): array
                {
                    return get_object_vars(\$this);
                }
            }

            PHP;

        return $this->writeStub('app/Dto', $name, $stub);
    }
}
