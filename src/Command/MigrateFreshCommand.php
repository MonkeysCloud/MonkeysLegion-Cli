<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Database\Contracts\ConnectionInterface;
use MonkeysLegion\Migration\Runner\BatchTracker;
use MonkeysLegion\Migration\Runner\MigrationRunner;

/**
 * Drop all tables and re-run all migrations from scratch.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('migrate:fresh', 'Drop all tables and re-run all migrations')]
final class MigrateFreshCommand extends Command
{
    public function __construct(
        private readonly ConnectionInterface $db,
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        if (!$this->hasOption('force')) {
            $this->alert('This will DROP ALL TABLES and re-run all migrations!');

            if (!$this->confirm('Are you sure?')) {
                $this->info('Cancelled.');

                return self::SUCCESS;
            }
        }

        $dir = $this->option('path');
        $dir = is_string($dir) ? $dir : (function_exists('base_path') ? base_path('database/migrations') : 'database/migrations');

        $this->info('🧹 Dropping all tables…');

        $runner = new MigrationRunner($this->db, new BatchTracker($this->db));
        $result = $runner->fresh($dir);

        if (!$result->success) {
            $this->error("❌ Fresh migration failed: {$result->error}");

            return self::FAILURE;
        }

        $rows = array_map(
            static fn(string $name): array => [$name, '✅'],
            $result->executed,
        );

        $this->table(['Migration', 'Status'], $rows);
        $this->comment(sprintf('  Duration: %.2f ms', $result->durationMs));
        $this->info('✅ Fresh migration completed.');

        return self::SUCCESS;
    }
}
