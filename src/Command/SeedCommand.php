<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Database\MySQL\Connection;

#[CommandAttr(
    'db:seed',
    'Run database seeders (optionally specify one)'
)]
final class SeedCommand extends Command
{
    public function __construct(private Connection $db)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $target = $_SERVER['argv'][2] ?? null;
        $path   = base_path('database/seeders');
        $files  = glob("{$path}/*Seeder.php");

        if (! $files) {
            $this->error("No seeders found in {$path}");
            return self::FAILURE;
        }

        foreach ($files as $file) {
            $classFile = basename($file, '.php');
            if ($target && stripos($classFile, $target) === false) {
                continue;
            }

            // load and run
            require_once $file;
            $fqcn = "App\\Database\\Seeders\\{$classFile}";
            if (! class_exists($fqcn)) {
                $this->error("Class {$fqcn} not found");
                continue;
            }
            $this->line("➤ Running {$classFile}...");
            (new $fqcn)->run($this->db);
        }

        $this->info('✅  Seeders complete.');
        return self::SUCCESS;
    }
}