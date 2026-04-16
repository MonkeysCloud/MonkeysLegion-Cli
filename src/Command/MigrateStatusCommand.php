<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Database\Contracts\ConnectionInterface;
use MonkeysLegion\Migration\Runner\BatchTracker;
use MonkeysLegion\Migration\Runner\MigrationRunner;

/**
 * Show migration status — ran/pending with batch info.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('migrate:status', 'Show ran and pending migrations', aliases: ['m:st'])]
final class MigrateStatusCommand extends Command
{
    public function __construct(
        private readonly ConnectionInterface $db,
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $dir = $this->option('path');
        $dir = is_string($dir) ? $dir : (function_exists('base_path') ? base_path('database/migrations') : 'database/migrations');

        $runner   = new MigrationRunner($this->db, new BatchTracker($this->db));
        $statuses = $runner->status($dir);

        if ($statuses === []) {
            $this->info('No migrations found.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($statuses as $status) {
            $rows[] = [
                $status->ran ? '✅' : '⬜',
                $status->name,
                $status->batch !== null ? (string) $status->batch : '—',
                $status->executedAt?->format('Y-m-d H:i:s') ?? '—',
            ];
        }

        $this->table(
            ['', 'Migration', 'Batch', 'Executed At'],
            $rows,
        );

        $ran     = count(array_filter($statuses, static fn($s) => $s->ran));
        $pending = count($statuses) - $ran;

        $this->comment("  {$ran} ran, {$pending} pending");

        return self::SUCCESS;
    }
}
