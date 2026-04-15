<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Generate an authorization policy class with CRUD methods.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('make:policy', 'Generate an authorization policy class')]
final class MakePolicyCommand extends Command
{
    use MakerHelpers;

    protected function handle(): int
    {
        $input = $this->argument(0) ?? $this->ask('Policy name (e.g., User):');
        $name  = $this->ensureSuffix($this->toPascalCase($input), 'Policy');

        if (!preg_match('/^[A-Z][A-Za-z0-9]+Policy$/', $name)) {
            $this->error('Invalid name — must be PascalCase ending with "Policy".');

            return self::FAILURE;
        }

        $entity = $this->removeSuffix($name, 'Policy');
        $dir    = function_exists('base_path') ? base_path('app/Policy') : 'app/Policy';
        $file   = "{$dir}/{$name}.php";

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        if (is_file($file)) {
            $this->comment("Policy already exists: {$file}");

            return self::SUCCESS;
        }

        $stub = <<<PHP
            <?php
            declare(strict_types=1);

            namespace App\\Policy;

            use App\\Entity\\{$entity};

            final class {$name}
            {
                public function view(mixed \$user, {$entity} \$model): bool
                {
                    // TODO: implement view authorization
                    return true;
                }

                public function create(mixed \$user): bool
                {
                    // TODO: implement create authorization
                    return true;
                }

                public function update(mixed \$user, {$entity} \$model): bool
                {
                    // TODO: implement update authorization
                    return true;
                }

                public function delete(mixed \$user, {$entity} \$model): bool
                {
                    // TODO: implement delete authorization
                    return false;
                }
            }
            PHP;

        file_put_contents($file, $stub);
        $this->info("✅ Created policy: {$file}");

        return self::SUCCESS;
    }
}