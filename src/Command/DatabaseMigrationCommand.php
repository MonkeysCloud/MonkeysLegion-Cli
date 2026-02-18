<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Entity\Scanner\EntityScanner;
use MonkeysLegion\Migration\MigrationGenerator;
use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Database\Contracts\ConnectionInterface;

#[CommandAttr('make:migration', 'Generate SQL diff from entities to MySQL schema')]
final class DatabaseMigrationCommand extends Command
{
    public function __construct(
        private ConnectionInterface $connection,
        private EntityScanner     $scanner,
        private MigrationGenerator $generator,
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        // 1. Locate entities
        $entities = $this->scanner->scanDir(\base_path('app/Entity'));

        // 2. Read current DB schema
        $pdo    = $this->connection->pdo();
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        $schema = [];

        if ($driver === 'mysql') {
            $tablesStmt = $this->safeQuery($pdo, "SHOW TABLES");
            $tables = $tablesStmt->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                $colsStmt = $this->safeQuery($pdo, "SHOW COLUMNS FROM `{$table}`");
                $cols = $colsStmt->fetchAll(\PDO::FETCH_ASSOC);

                $schema[$table] = [];
                foreach ($cols as $col) {
                    $type = (string) ($col['Type'] ?? '');
                    $schema[$table][$col['Field']] = [
                        'type'     => $type,
                        'nullable' => (strtoupper($col['Null'] ?? '') === 'YES'),
                        'default'  => $col['Default'] ?? null,
                        'length'   => preg_match('/\((.*)\)/', $type, $matches) ? $matches[1] : null,
                    ];
                }
            }
        } elseif ($driver === 'pgsql') {
            $tablesStmt = $this->safeQuery($pdo, "SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
            $tables = $tablesStmt->fetchAll(\PDO::FETCH_COLUMN);

            $colSql = 'SELECT column_name, data_type, is_nullable, column_default 
                       FROM information_schema.columns 
                       WHERE table_schema = \'public\' AND table_name = :table';
            $colsStmt = $pdo->prepare($colSql);

            foreach ($tables as $table) {
                $colsStmt->execute(['table' => $table]);
                $cols = $colsStmt->fetchAll(\PDO::FETCH_ASSOC);

                $schema[$table] = [];
                foreach ($cols as $col) {
                    $schema[$table][$col['column_name']] = [
                        'type'     => $col['data_type'] ?? '',
                        'nullable' => (strtoupper($col['is_nullable'] ?? '') === 'YES'),
                        'default'  => $col['column_default'] ?? null,
                    ];
                }
            }
        }

        // 3. Generate diff SQL
        $sql = $this->generator->diff($entities, $schema);

        if ($sql === '') {
            $this->info('Nothing to migrate - database already matches entities.');
            return self::SUCCESS;
        }

        // 4. Ensure var/migrations directory exists
        $dir = \base_path('var/migrations');
        if (!\is_dir($dir) && !\mkdir($dir, 0o775, recursive: true) && !\is_dir($dir)) {
            throw new \RuntimeException("Unable to create migrations directory: {$dir}");
        }

        // 5. Write a migration file
        $file = $dir . '/' . date('Y_m_d_His') . '_auto.sql';
        \file_put_contents($file, $sql);

        $this->info("Created migration: {$file}");
        return self::SUCCESS;
    }
}
