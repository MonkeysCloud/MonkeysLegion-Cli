<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * Generate a backed enum.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('make:enum', 'Generate a backed enum class')]
final class MakeEnumCommand extends Command
{
    use MakerHelpers;

    protected function handle(): int
    {
        $name = $this->argument(0) ?? $this->ask('Enum name (e.g., OrderStatus):');

        if (trim($name) === '') {
            return $this->fail('Enum name is required.');
        }

        $name     = $this->toPascalCase($name);
        $backing  = $this->choice('Backing type?', ['string', 'int'], 0);
        $phpType  = $backing === 'int' ? 'int' : 'string';

        // Ask for cases
        $cases = [];
        $this->info('Enter enum cases (empty name to stop):');

        while (true) {
            $caseName = $this->ask('  Case name:');

            if (trim($caseName) === '') {
                break;
            }

            $caseValue = $this->ask("  Value for {$caseName}:");
            $cases[]   = ['name' => strtoupper($this->toSnakeCase($caseName)), 'value' => $caseValue];
        }

        $caseLines = '';

        foreach ($cases as $case) {
            $val        = $phpType === 'int' ? $case['value'] : "'{$case['value']}'";
            $caseLines .= "    case {$case['name']} = {$val};\n";
        }

        $stub = <<<PHP
            <?php
            declare(strict_types=1);

            namespace App\\Enum;

            /**
             * {$name} enum.
             */
            enum {$name}: {$phpType}
            {
            {$caseLines}
                /**
                 * Human-readable label.
                 */
                public function label(): string
                {
                    return match (\$this) {
                        default => ucfirst(strtolower(str_replace('_', ' ', \$this->name))),
                    };
                }

                /**
                 * UI color for display.
                 */
                public function color(): string
                {
                    return match (\$this) {
                        default => 'gray',
                    };
                }

                /**
                 * Icon identifier.
                 */
                public function icon(): string
                {
                    return match (\$this) {
                        default => 'circle',
                    };
                }
            }

            PHP;

        return $this->writeStub('app/Enum', $name, $stub);
    }
}
