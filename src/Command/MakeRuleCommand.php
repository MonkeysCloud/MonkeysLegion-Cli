<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * MonKeysLegion Framework — CLI Package
 *
 * Generate a custom validation rule class implementing ConstraintInterface.
 *
 * Usage:
 *   php bin/ml make:rule EvenNumber
 *   php bin/ml make:rule ValidEmail --force
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('make:rule', 'Generate a validation rule class')]
final class MakeRuleCommand extends Command
{
    use MakerHelpers;

    protected function handle(): int
    {
        $name = $this->argument(0) ?? $this->ask('Rule name (e.g., EvenNumber):');

        if (trim($name) === '') {
            return $this->fail('Rule name is required.');
        }

        $name = $this->toPascalCase($name);

        // Ensure suffix "Rule" is optional — ask user
        $hasRuleSuffix = str_ends_with($name, 'Rule');
        if (!$hasRuleSuffix && $this->confirm('Append "Rule" suffix?', true)) {
            $name .= 'Rule';
        }

        $stub = <<<PHP
            <?php
            declare(strict_types=1);

            namespace App\\Rule;

            use Attribute;
            use MonkeysLegion\\Validation\\Contracts\\ConstraintInterface;
            use MonkeysLegion\\Validation\\ValidationError;

            /**
             * {$name} — custom validation rule.
             *
             * Apply to DTO properties:
             *   #[{$name}]
             *   public string \$field;
             */
            #[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
            final class {$name} implements ConstraintInterface
            {
                public function __construct(
                    public readonly string \$message = 'This value is invalid.',
                ) {}

                public function validate(mixed \$value, string \$field, object \$dto): ?ValidationError
                {
                    // TODO: Implement your validation logic.
                    // Return a ValidationError if invalid, or null if valid.
                    //
                    // Example:
                    //   if (!is_valid(\$value)) {
                    //       return new ValidationError(\$field, \$this->message);
                    //   }
                    //   return null;

                    return null;
                }
            }

            PHP;

        return $this->writeStub('app/Rule', $name, $stub);
    }
}
