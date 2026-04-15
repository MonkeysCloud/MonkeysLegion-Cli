<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Database\Contracts\ConnectionInterface;
use MonkeysLegion\Migration\Runner\BatchTracker;
use MonkeysLegion\Migration\Runner\MigrationRunner;

/**
 * Rollback all and re-run all migrations.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('migrate:refresh', 'Rollback all and re-run all migrations')]
final class MigrateRefreshCommand extends Command
{
    public function __construct(
        private readonly ConnectionInterface $db,
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        if (!$this->hasOption('force')) {
            $this->alert('This will rollback ALL migrations and re-run them!');

            if (!$this->confirm('Are you sure?')) {
                $this->info('Cancelled.');

                return self::SUCCESS;
            }
        }

        $dir = $this->option('path');
        $dir = is_string($dir) ? $dir : (function_exists('base_path') ? base_path('database/migrations') : 'database/migrations');

        $this->info('🔄 Refreshing migrations…');

        $runner = new MigrationRunner($this->db, new BatchTracker($this->db));
        $result = $runner->refresh($dir);

        if (!$result->success) {
            $this->error("❌ Refresh failed: {$result->error}");

            return self::FAILURE;
        }

        $rows = array_map(
            static fn(string $name): array => [$name, '✅'],
            $result->executed,
        );

        $this->table(['Migration', 'Status'], $rows);
        $this->comment(sprintf('  Duration: %.2f ms', $result->durationMs));
        $this->info('✅ Migration refresh completed.');

        return self::SUCCESS;
    }
}
