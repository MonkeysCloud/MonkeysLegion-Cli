<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Database\Contracts\ConnectionInterface;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use PDO;
use PDOException;

#[CommandAttr('migrate', 'Run outstanding migrations')]
final class MigrateCommand extends Command
{
    private const MIGRATIONS_TABLE = 'ml_migrations';

    public function __construct(private ConnectionInterface $connection)
    {
        parent::__construct();
    }

    protected function handle(): int
    {
        try {
            $pdo = $this->connection->pdo();

            /* -----------------------------------------------------------------
         | 1) Ensure the bookkeeping table exists
         * ----------------------------------------------------------------*/
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $pdo->exec($this->migrationsTableDdl($driver));

            /* -----------------------------------------------------------------
         | 2) Determine pending migrations
         * ----------------------------------------------------------------*/
            $table = self::MIGRATIONS_TABLE;
            $applied = $this->safeQuery($pdo, "SELECT filename FROM {$table}")
                ->fetchAll(PDO::FETCH_COLUMN);

            $files   = \glob(\base_path('var/migrations/*.sql')) ?: [];
            // Normalize paths to absolute for comparison
            $applied = array_map(fn($f) => str_starts_with($f, '/') ? $f : \base_path($f), $applied);
            $pending = \array_values(\array_diff($files, $applied));

            if ($pending === []) {
                $this->info('Nothing to migrate.');
                return self::SUCCESS;
            }

            $batch = (int) (
                $this->safeQuery($pdo, "SELECT MAX(batch) FROM {$table}")
                ->fetchColumn() ?: 0
            ) + 1;

            /* -----------------------------------------------------------------
         | 3) Run each file in its own guarded transaction
         * ----------------------------------------------------------------*/
            foreach ($pending as $file) {
                $pdo->beginTransaction();

                try {
                    $sql = \file_get_contents($file);
                    if (!$sql) {
                        throw new \RuntimeException("Failed to read SQL file: {$file}");
                    }

                    // Execute each SQL statement separately
                    $statements = preg_split('/;\s*(?=\r?\n|$)/', trim($sql));
                    if (!$statements) {
                        throw new \RuntimeException("Failed to parse SQL file: {$file}");
                    }
                    foreach ($statements as $stmt) {
                        $stmt = trim($stmt);
                        if ($stmt === '') {
                            continue;
                        }
                        try {
                            // Debugging log if necessary, but keep it clean
                            $pdo->exec($stmt);
                        } catch (PDOException $e) {
                            // Skip duplicate-column or table-exists errors
                            // MySQL: 42S21 (dup column), 42S01 (dup table)
                            // PostgreSQL: 42P07 (dup table), 42701 (dup column)
                            if (in_array($e->getCode(), ['42S21', '42S01', '42P07', '42701'], true)) {
                                $this->line('Skipped (already applied statement): ' . substr($stmt, 0, 50) . '…');
                                continue;
                            }
                            throw $e;
                        }
                    }

                    // Record as applied
                    $stmt = $pdo->prepare(
                        'INSERT INTO ' . self::MIGRATIONS_TABLE . ' (filename, batch) VALUES (?, ?)'
                    );
                    $stmt->execute([$file, $batch]);

                    // Commit if still in a transaction
                    if ($pdo->inTransaction()) {
                        $pdo->commit();
                    }

                    $this->line('Migrated: ' . \basename($file));
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $this->error('Migration failed: ' . $e->getMessage());
                    return self::FAILURE;
                }
            }

            $this->info('Migrations complete (batch ' . $batch . ').');
            return self::SUCCESS;
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Return the CREATE TABLE DDL for the migrations bookkeeping table,
     * adapted to the current database driver.
     */
    private function migrationsTableDdl(string $driver): string
    {
        $table = self::MIGRATIONS_TABLE;

        if ($driver === 'pgsql') {
            return "CREATE TABLE IF NOT EXISTS {$table} (
                id          SERIAL PRIMARY KEY,
                filename    VARCHAR(255) NOT NULL,
                batch       INT NOT NULL,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
        }

        // MySQL (default)
        return "CREATE TABLE IF NOT EXISTS {$table} (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            filename    VARCHAR(255) NOT NULL,
            batch       INT NOT NULL,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }
}
