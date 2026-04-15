<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Database\Contracts\ConnectionInterface;

/**
 * Drop all tables in the database.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('db:wipe', 'Drop all tables in the database')]
final class DbWipeCommand extends Command
{
    public function __construct(
        private readonly ConnectionInterface $db,
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        if (!$this->hasOption('force')) {
            $this->alert('This will DROP ALL TABLES in the database!');

            if (!$this->confirm('Are you absolutely sure?')) {
                $this->info('Cancelled.');

                return self::SUCCESS;
            }
        }

        $pdo    = $this->db->pdo();
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        $tables = match ($driver) {
            'mysql' => $this->getMysqlTables($pdo),
            'pgsql' => $this->getPgsqlTables($pdo),
            'sqlite' => $this->getSqliteTables($pdo),
            default => [],
        };

        if ($tables === []) {
            $this->info('No tables to drop.');

            return self::SUCCESS;
        }

        $this->info("Dropping {" . count($tables) . "} tables…");

        // Disable FK checks during drop
        match ($driver) {
            'mysql'  => $pdo->exec('SET FOREIGN_KEY_CHECKS = 0'),
            'sqlite' => $pdo->exec('PRAGMA foreign_keys = OFF'),
            default  => null,
        };

        $dropped = 0;

        foreach ($tables as $table) {
            try {
                $pdo->exec("DROP TABLE IF EXISTS \"{$table}\" CASCADE");
                $dropped++;
            } catch (\PDOException $e) {
                $this->warn("Failed to drop '{$table}': {$e->getMessage()}");
            }
        }

        // Re-enable FK checks
        match ($driver) {
            'mysql'  => $pdo->exec('SET FOREIGN_KEY_CHECKS = 1'),
            'sqlite' => $pdo->exec('PRAGMA foreign_keys = ON'),
            default  => null,
        };

        $this->info("✅ Dropped {$dropped} tables.");

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function getMysqlTables(\PDO $pdo): array
    {
        $stmt = $pdo->query('SHOW TABLES');

        return $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
    }

    /**
     * @return list<string>
     */
    private function getPgsqlTables(\PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT tablename FROM pg_tables WHERE schemaname = current_schema()",
        );

        return $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
    }

    /**
     * @return list<string>
     */
    private function getSqliteTables(\PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'",
        );

        return $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
    }
}
