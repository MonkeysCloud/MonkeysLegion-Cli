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
 * Run pending migrations.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('migrate', 'Run pending database migrations', aliases: ['m'])]
final class MigrateCommand extends Command
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

        $dir = $this->getMigrationsDir();

        $this->info('🚀 Running migrations…');

        $runner = new MigrationRunner($this->db, new BatchTracker($this->db));
        $result = $runner->run($dir, $steps);

        if ($result->executed === []) {
            $this->info('Nothing to migrate.');

            return self::SUCCESS;
        }

        $rows = array_map(
            static fn(string $name): array => [$name, '✅'],
            $result->executed,
        );

        if ($result->failed !== []) {
            foreach ($result->failed as $name) {
                $rows[] = [$name, '❌'];
            }
        }

        $this->table(['Migration', 'Status'], $rows);
        $this->comment(sprintf('  Duration: %.2f ms', $result->durationMs));

        if (!$result->success) {
            $this->error("❌ Migration failed: {$result->error}");

            return self::FAILURE;
        }

        $this->info('✅ All migrations completed.');

        return self::SUCCESS;
    }

    private function getMigrationsDir(): string
    {
        $dir = $this->option('path');

        if (is_string($dir)) {
            return $dir;
        }

        return function_exists('base_path') ? base_path('database/migrations') : 'database/migrations';
    }
}
