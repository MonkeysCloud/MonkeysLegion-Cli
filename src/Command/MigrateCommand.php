<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Database\MySQL\Connection;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cli\Concerns\Confirmable;
use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use PDO;
use PDOException;

#[CommandAttr('migrate', 'Run outstanding migrations')]
final class MigrateCommand extends Command
{
    use Confirmable;

    private const MIGRATIONS_TABLE = 'ml_migrations';

    public function __construct(private Connection $connection)
    {
        parent::__construct();
    }

    protected function handle(): int
    {
        $pdo = $this->connection->pdo();

        /* -----------------------------------------------------------------
         | 1) Ensure the bookkeeping table exists
         * ----------------------------------------------------------------*/
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS '.self::MIGRATIONS_TABLE.' (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                filename    VARCHAR(255) NOT NULL,
                batch       INT NOT NULL,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        /* -----------------------------------------------------------------
         | 2) Determine pending migrations
         * ----------------------------------------------------------------*/
        $applied = $pdo->query('SELECT filename FROM '.self::MIGRATIONS_TABLE)
            ->fetchAll(PDO::FETCH_COLUMN);

        $files   = \glob(\base_path('var/migrations/*.sql')) ?: [];
        $pending = \array_values(\array_diff($files, $applied));

        if ($pending === []) {
            $this->info('Nothing to migrate.');
            return self::SUCCESS;
        }

        $batch = (int) ($pdo->query('SELECT MAX(batch) FROM '.self::MIGRATIONS_TABLE)
                ->fetchColumn() ?: 0) + 1;

        /* -----------------------------------------------------------------
         | 3) Run each file in its own guarded transaction
         * ----------------------------------------------------------------*/
        foreach ($pending as $file) {
            $pdo->beginTransaction();

            try {
                $sql = \file_get_contents($file);

                // Some statements (DDL) trigger an implicit commit in MySQL.
                // We still wrap everything so failures are caught consistently.
                try {
                    $pdo->exec($sql);
                } catch (PDOException $e) {
                    /* ------------------------------------------------------
                     * Handle idempotent errors gracefully
                     * 42S21 = duplicate column / field
                     * 42S01 = table already exists
                     * -----------------------------------------------------*/
                    if (\in_array($e->getCode(), ['42S21', '42S01'], true)) {
                        $this->warn('Skipped (already applied): '.\basename($file));
                    } else {
                        throw $e; // real failure → bubble up to outer catch
                    }
                }

                // Record as applied
                $stmt = $pdo->prepare(
                    'INSERT INTO '.self::MIGRATIONS_TABLE.' (filename, batch) VALUES (?, ?)'
                );
                $stmt->execute([$file, $batch]);

                // Commit if still in a transaction
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }

                $this->line('Migrated: '.\basename($file));
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $this->error('Migration failed: '.$e->getMessage());
                return self::FAILURE;
            }
        }

        $this->info('Migrations complete (batch '.$batch.').');
        return self::SUCCESS;
    }
}