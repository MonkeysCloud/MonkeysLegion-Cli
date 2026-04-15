<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Database\Contracts\ConnectionInterface;
use MonkeysLegion\Migration\Runner\BatchTracker;
use MonkeysLegion\Migration\Runner\MigrationRunner;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Rollback the last migration batch.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('migrate:rollback', 'Rollback the last migration batch', aliases: ['m:rb'])]
final class RollbackCommand extends Command
{
    public function __construct(
        private readonly ConnectionInterface $db,
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $steps = $this->option('step');
        $steps = is_numeric($steps) ? (int) $steps : null;

        $batch = $this->option('batch');
        $batch = is_numeric($batch) ? (int) $batch : null;

        if (!$this->confirm('⚠️  Are you sure you want to rollback?')) {
            $this->info('Rollback cancelled.');

            return self::SUCCESS;
        }

        $this->info('⏪ Rolling back migrations…');

        $runner = new MigrationRunner($this->db, new BatchTracker($this->db));
        $result = $runner->rollback($steps, $batch);

        if ($result->executed === []) {
            $this->info('Nothing to rollback.');

            return self::SUCCESS;
        }

        $rows = array_map(
            static fn(string $name): array => [$name, '⏪'],
            $result->executed,
        );

        $this->table(['Migration', 'Status'], $rows);
        $this->comment(sprintf('  Duration: %.2f ms', $result->durationMs));

        if (!$result->success) {
            $this->error("❌ Rollback failed: {$result->error}");

            return self::FAILURE;
        }

        $this->info('✅ Rollback completed.');

        return self::SUCCESS;
    }
}
