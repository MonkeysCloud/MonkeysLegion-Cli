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

    private const string MIGRATIONS_TABLE = 'ml_migrations';

    public function __construct(private Connection $connection)
    {
        parent::__construct();
    }

    protected function handle(): int
    {
        $pdo = $this->connection->pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS '.self::MIGRATIONS_TABLE.' (
                id INT AUTO_INCREMENT PRIMARY KEY,
                filename VARCHAR(255) NOT NULL,
                batch INT NOT NULL,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $applied = $pdo->query(
            'SELECT filename FROM '.self::MIGRATIONS_TABLE
        )->fetchAll(\PDO::FETCH_COLUMN);

        $files = \glob(\base_path('var/migrations/*.sql'));
        $pending = \array_values(\array_diff($files, $applied));

        if ($pending === []) {
            $this->info('Nothing to migrate.');
            return self::SUCCESS;
        }

        $batch = ($pdo->query('SELECT MAX(batch) FROM '.self::MIGRATIONS_TABLE)->fetchColumn() ?: 0) + 1;

        $pdo->beginTransaction();
        try {
            foreach ($pending as $file) {
                $sql = \file_get_contents($file);
                $pdo->exec($sql);
                $stmt = $pdo->prepare('INSERT INTO '.self::MIGRATIONS_TABLE.' (filename,batch) VALUES (?,?)');
                $stmt->execute([$file, $batch]);
                $this->line('Migrated: '.\basename($file));
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $this->error('Migration failed: '.$e->getMessage());
            return self::FAILURE;
        }

        $this->info('Migrations complete (batch '.$batch.').');
        return self::SUCCESS;
    }
}