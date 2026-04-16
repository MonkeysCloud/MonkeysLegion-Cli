<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('make:resource', 'Generate a JSON:API resource class')]
final class MakeResourceCommand extends Command
{
    use MakerHelpers;

    protected function handle(): int
    {
        $name = $this->argument(0) ?? $this->ask('Resource name (e.g., UserResource):');

        if (trim($name) === '') {
            return $this->fail('Resource name is required.');
        }

        $name   = $this->ensureSuffix($this->toPascalCase($name), 'Resource');
        $entity = $this->removeSuffix($name, 'Resource');

        $stub = <<<PHP
            <?php
            declare(strict_types=1);

            namespace App\\Resource;

            /**
             * {$name} — JSON:API resource transformation for {$entity}.
             */
            final class {$name}
            {
                /**
                 * Transform a single entity.
                 *
                 * @return array<string, mixed>
                 */
                public function toArray(object \$entity): array
                {
                    return [
                        'id'         => \$entity->id ?? null,
                        'type'       => '{$this->toSnakeCase($entity)}',
                        'attributes' => [
                            // Map entity properties to API attributes
                        ],
                        'meta'       => [
                            'created_at' => \$entity->created_at ?? null,
                        ],
                    ];
                }

                /**
                 * Transform a collection.
                 *
                 * @param iterable<object> \$entities
                 * @return list<array<string, mixed>>
                 */
                public function collection(iterable \$entities): array
                {
                    \$data = [];

                    foreach (\$entities as \$entity) {
                        \$data[] = \$this->toArray(\$entity);
                    }

                    return \$data;
                }
            }

            PHP;

        return $this->writeStub('app/Resource', $name, $stub);
    }
}
