<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Database\MySQL\Connection;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;

#[CommandAttr('rollback', 'Undo the last batch of migrations')]
final class RollbackCommand extends Command
{
    public function __construct(private Connection $connection)
    {
        parent::__construct();
    }

    protected function handle(): int
    {
        $pdo = $this->connection->pdo();
        $last = $pdo->query('SELECT MAX(batch) FROM ml_migrations')->fetchColumn();

        if (!$last) {
            $this->info('No migrations have been run.');
            return self::SUCCESS;
        }

        $files = $pdo->prepare('SELECT filename FROM ml_migrations WHERE batch = ? ORDER BY id DESC');
        $files->execute([$last]);
        $files = $files->fetchAll(\PDO::FETCH_COLUMN);

        if ($files === []) {
            $this->info('Nothing to roll back.');
            return self::SUCCESS;
        }

        $pdo->beginTransaction();
        try {
            foreach ($files as $file) {
                // Expect a matching *_down.sql sibling; fallback: warn + skip.
                $down = preg_replace('/_auto\.sql$/', '_down.sql', $file);
                if (!\is_file($down)) {
                    throw new \RuntimeException("Missing rollback file for {$file}");
                }
                $pdo->exec(\file_get_contents($down));
                $stmt = $pdo->prepare('DELETE FROM ml_migrations WHERE filename = ?');
                $stmt->execute([$file]);
                $this->line('Rolled back: '.\basename($file));
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $this->error('Rollback failed: '.$e->getMessage());
            return self::FAILURE;
        }

        $this->info('Rollback complete (batch '.$last.').');
        return self::SUCCESS;
    }
}