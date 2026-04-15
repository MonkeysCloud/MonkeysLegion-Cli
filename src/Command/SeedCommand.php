<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Database\Contracts\ConnectionInterface;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Run database seeders.
 *
 * Usage:
 *   php ml db:seed              # run all seeders
 *   php ml db:seed UsersSeeder  # run specific seeder
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('db:seed', 'Run database seeders')]
final class SeedCommand extends Command
{
    public function __construct(
        private readonly ConnectionInterface $db,
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $target = $this->argument(0);
        $path   = function_exists('base_path') ? base_path('database/seeders') : 'database/seeders';
        $files  = glob("{$path}/*Seeder.php") ?: [];

        if ($files === []) {
            $this->warn("No seeders found in {$path}");

            return self::FAILURE;
        }

        $ran = 0;

        foreach ($files as $file) {
            $classFile = basename($file, '.php');

            // Filter by target if specified
            if (is_string($target) && $target !== '' && stripos($classFile, $target) === false) {
                continue;
            }

            require_once $file;

            $fqcn = "App\\Database\\Seeders\\{$classFile}";

            if (!class_exists($fqcn)) {
                $this->error("Class {$fqcn} not found in {$file}");

                continue;
            }

            $this->cliLine()
                ->add('  ➤ ', 'cyan')
                ->add("Running {$classFile}...", 'white')
                ->print();

            (new $fqcn())->run($this->db);
            $ran++;
        }

        if ($ran === 0) {
            $this->warn('No matching seeders were executed.');

            return self::FAILURE;
        }

        $this->info("✅ {$ran} seeder(s) complete.");

        return self::SUCCESS;
    }
}
