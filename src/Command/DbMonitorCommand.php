<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Database\Contracts\ConnectionInterface;

/**
 * MonKeysLegion Framework — CLI Package
 *
 * Monitor database health: connections, sizes, table counts, and slow queries.
 *
 * Usage:
 *   php bin/ml db:monitor                  # Show database health
 *   php bin/ml db:monitor --watch          # Continuous monitoring (2s refresh)
 *   php bin/ml db:monitor --slow=100       # Show queries slower than 100ms
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('db:monitor', 'Monitor database health and active connections')]
final class DbMonitorCommand extends Command
{
    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $watch = $this->hasOption('watch');
        $slow  = (int) ($this->option('slow', 0));

        do {
            if ($watch) {
                // Clear screen for refresh
                echo "\033[2J\033[H";
            }

            $this->displayHealth();

            if ($slow > 0) {
                $this->displaySlowQueries($slow);
            }

            if ($watch) {
                echo "\n\033[90mRefreshing in 2s... (Ctrl+C to stop)\033[0m\n";
                sleep(2);
            }
        } while ($watch);

        return self::SUCCESS;
    }

    private function displayHealth(): void
    {
        $driver = $this->connection->getDriver();

        $this->alert('Database Monitor');
        $this->newLine();

        // Connection info
        $this->table(
            ['Metric', 'Value'],
            [
                ['Driver', $driver->value],
                ['Connected', $this->connection->isConnected() ? '✅ Yes' : '❌ No'],
                ['In Transaction', $this->connection->inTransaction() ? '⚠️ Yes' : 'No'],
                ['Transaction Depth', (string) $this->connection->transactionDepth()],
            ]
        );
        $this->newLine();

        // Driver-specific info
        match ($driver->value) {
            'mysql'      => $this->displayMySqlInfo(),
            'pgsql'      => $this->displayPostgresInfo(),
            'sqlite'     => $this->displaySqliteInfo(),
            default      => $this->comment("(No specific monitoring for driver: {$driver->value})"),
        };
    }

    private function displayMySqlInfo(): void
    {
        try {
            // Process list
            $processes = $this->connection->query('SHOW PROCESSLIST')->fetchAll();
            $this->comment('Active Connections (' . count($processes) . '):');
            $rows = [];
            foreach ($processes as $p) {
                $rows[] = [
                    $p['Id'] ?? '?',
                    $p['User'] ?? '?',
                    $p['Host'] ?? '?',
                    $p['db'] ?? '-',
                    $p['Command'] ?? '?',
                    $p['Time'] ?? '0',
                    $p['State'] ?? '-',
                    substr($p['Info'] ?? '-', 0, 50),
                ];
            }
            if ($rows !== []) {
                $this->table(['ID', 'User', 'Host', 'DB', 'Command', 'Time(s)', 'State', 'Info'], $rows);
            } else {
                $this->line('  No active connections.');
            }

            $this->newLine();

            // Database size
            $size = $this->connection->query(
                "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()"
            )->fetch();

            if (is_array($size) && isset($size['size_mb'])) {
                $this->info("Database size: {$size['size_mb']} MB");
            }

            // Table count
            $tables = $this->connection->query('SHOW TABLES')->fetchAll();
            $this->info('Table count: ' . count($tables));

        } catch (\Throwable $e) {
            $this->error("Failed to get MySQL info: {$e->getMessage()}");
        }
    }

    private function displayPostgresInfo(): void
    {
        try {
            // Active connections
            $conns = $this->connection->query(
                "SELECT pid, usename, application_name, state, query
                 FROM pg_stat_activity
                 WHERE datname = current_database()"
            )->fetchAll();

            $this->comment('Active Connections (' . count($conns) . '):');
            $rows = [];
            foreach ($conns as $c) {
                $rows[] = [
                    $c['pid'] ?? '?',
                    $c['usename'] ?? '?',
                    $c['state'] ?? 'idle',
                    substr($c['query'] ?? '', 0, 60),
                ];
            }
            if ($rows !== []) {
                $this->table(['PID', 'User', 'State', 'Query'], $rows);
            } else {
                $this->line('  No active connections.');
            }

            $this->newLine();

            // Database size
            $size = $this->connection->query(
                "SELECT pg_size_pretty(pg_database_size(current_database())) AS size"
            )->fetch();

            if (is_array($size) && isset($size['size'])) {
                $this->info("Database size: {$size['size']}");
            }

            // Table count
            $tables = $this->connection->query(
                "SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = 'public'"
            )->fetch();

            if (is_array($tables) && isset($tables['cnt'])) {
                $this->info('Table count: ' . $tables['cnt']);
            }

        } catch (\Throwable $e) {
            $this->error("Failed to get PostgreSQL info: {$e->getMessage()}");
        }
    }

    private function displaySqliteInfo(): void
    {
        try {
            // Table list
            $tables = $this->connection->query(
                "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
            )->fetchAll();

            $this->comment('Tables (' . count($tables) . '):');
            $rows = [];
            foreach ($tables as $t) {
                $name = $t['name'] ?? '?';

                // Row count per table
                $count = $this->connection->query("SELECT COUNT(*) AS cnt FROM {$name}")->fetch();
                $rows[] = [$name, $count['cnt'] ?? '?'];
            }
            if ($rows !== []) {
                $this->table(['Table', 'Rows'], $rows);
            } else {
                $this->line('  No tables found.');
            }

            $this->newLine();
            $this->info('Database: in-memory or file-based (SQLite)');

        } catch (\Throwable $e) {
            $this->error("Failed to get SQLite info: {$e->getMessage()}");
        }
    }

    private function displaySlowQueries(int $thresholdMs): void
    {
        $this->newLine();
        $this->comment("Slow queries (>{$thresholdMs}ms):");

        // Query logging may or may not be available depending on connection implementation.
        // We use method_exists to safely check without coupling to a specific class.
        try {
            if (!method_exists($this->connection, 'getQueryLog')) {
                $this->line('  Query logging is not available for this connection.');
                return;
            }

            /** @var list<array<string, mixed>> $log */
            $log = $this->connection->getQueryLog();
            if ($log === []) {
                $this->line('  No queries logged. Enable query logging to track slow queries.');
                return;
            }

            $slow = array_filter($log, fn($entry) => (float) ($entry['time_ms'] ?? 0) > $thresholdMs);

            if ($slow === []) {
                $this->line("  No queries slower than {$thresholdMs}ms.");
                return;
            }

            $rows = [];
            foreach (array_slice(array_values($slow), -20) as $entry) {
                $rows[] = [
                    round((float) ($entry['time_ms'] ?? 0), 2) . 'ms',
                    substr((string) ($entry['sql'] ?? ''), 0, 80),
                ];
            }

            $this->table(['Time', 'SQL'], $rows);
        } catch (\Throwable) {
            $this->line('  Query logging is not available for this connection.');
        }
    }
}
