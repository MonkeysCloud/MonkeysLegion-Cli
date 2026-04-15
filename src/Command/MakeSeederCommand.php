<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Generate a database seeder class.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('make:seeder', 'Generate a database seeder class')]
final class MakeSeederCommand extends Command
{
    use MakerHelpers;

    protected function handle(): int
    {
        $input = $this->argument(0) ?? $this->ask('Seeder name (e.g., Users):');
        $name  = $this->ensureSuffix($this->toPascalCase($input), 'Seeder');

        if (!preg_match('/^[A-Z][A-Za-z0-9]+Seeder$/', $name)) {
            $this->error('Invalid name — must be PascalCase ending with "Seeder".');

            return self::FAILURE;
        }

        $dir  = function_exists('base_path') ? base_path('database/seeders') : 'database/seeders';
        $file = "{$dir}/{$name}.php";

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        if (is_file($file)) {
            $this->comment("Seeder already exists: {$file}");

            return self::SUCCESS;
        }

        $stub = <<<PHP
            <?php
            declare(strict_types=1);

            namespace App\\Database\\Seeders;

            use MonkeysLegion\\Database\\Contracts\\ConnectionInterface;

            final class {$name}
            {
                /**
                 * Run the database seeds.
                 */
                public function run(ConnectionInterface \$db): void
                {
                    // TODO: implement seed logic
                    // \$db->pdo()->exec("INSERT INTO ...");
                }
            }
            PHP;

        file_put_contents($file, $stub);
        $this->info("✅ Created seeder: {$file}");

        return self::SUCCESS;
    }
}
