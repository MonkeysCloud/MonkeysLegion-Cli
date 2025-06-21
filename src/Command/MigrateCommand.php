<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Database\MySQL\Connection;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cli\Concerns\Confirmable;
use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;

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

        // 1. Ensure bookkeeping table exists
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS '.self::MIGRATIONS_TABLE.' (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                filename    VARCHAR(255) NOT NULL,
                batch       INT NOT NULL,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        // 2. Determine pending migrations
        $applied  = $pdo->query('SELECT filename FROM '.self::MIGRATIONS_TABLE)
            ->fetchAll(\PDO::FETCH_COLUMN);
        $files    = \glob(\base_path('var/migrations/*.sql')) ?: [];
        $pending  = \array_values(\array_diff($files, $applied));

        if ($pending === []) {
            $this->info('Nothing to migrate.');
            return self::SUCCESS;
        }

        $batch = (int) ($pdo->query('SELECT MAX(batch) FROM '.self::MIGRATIONS_TABLE)
                ->fetchColumn() ?: 0) + 1;

        // 3. Run each file in its own guarded transaction
        foreach ($pending as $file) {
            $pdo->beginTransaction();
            try {
                $sql = \file_get_contents($file);
                $pdo->exec($sql);                       // may contain DDL → implicit commit

                // Record as applied (outside the implicit commit scope)
                $stmt = $pdo->prepare(
                    'INSERT INTO '.self::MIGRATIONS_TABLE.' (filename, batch) VALUES (?, ?)'
                );
                $stmt->execute([$file, $batch]);

                // Commit if the transaction is still open
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