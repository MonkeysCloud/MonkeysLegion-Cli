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
        $stmt = $this->connection->pdo()->query(
            'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()'
        );

        if ($stmt === false) {
            // Handle query error (throw, log, or return)
            $this->error('Failed to fetch DB schema.');
            return self::FAILURE;
        }

        $schema = $stmt->fetchAll(\PDO::FETCH_GROUP | \PDO::FETCH_UNIQUE);

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
