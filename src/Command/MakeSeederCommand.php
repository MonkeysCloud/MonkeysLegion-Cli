<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

#[CommandAttr(
    'make:seeder',
    'Generate a new database seeder class stub'
)]
final class MakeSeederCommand extends Command
{
    public function handle(): int
    {
        $argv = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
        $name = isset($argv[2]) && is_string($argv[2]) ? $argv[2] : $this->ask('Enter seeder name (e.g. UsersTable)');

        if (!preg_match('/^[A-Z][A-Za-z0-9]+$/', $name)) {
            return $this->fail('Invalid seeder name – must start with uppercase.');
        }

        $classname = $name . 'Seeder';
        $dir       = base_path('database/seeders');
        $file      = "{$dir}/{$classname}.php";

        @mkdir($dir, 0755, true);
        if (is_file($file)) {
            $this->line("ℹ️  Seeder already exists: {$file}");
            return self::SUCCESS;
        }

        $stub = <<<PHP
                <?php
                declare(strict_types=1);

                namespace App\Database\Seeders;

                use MonkeysLegion\Database\MySQL\Connection;

                class {$classname}
                {
                    /**
                     * Run the database seeds.
                     */
                    public function run(Connection \$db): void
                    {
                        // TODO: implement your seed logic here, e.g.:
                        // \$db->pdo()->exec(\"INSERT INTO users (name,email) VALUES ('Alice','alice@example.com')\");
                    }
                }
                PHP;

        file_put_contents($file, $stub);
        $this->info("✅  Created seeder stub: {$file}");
        return self::SUCCESS;
    }

    private function fail(string $msg): int
    {
        $this->error($msg);
        return self::FAILURE;
    }
}
