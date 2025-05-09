<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Database\MySQL\Connection;
use MonkeysLegion\Entity\Scanner\EntityScanner;
use MonkeysLegion\Migration\MigrationGenerator;
use MonkeysLegion\Cli\Concerns\Confirmable;
use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

#[CommandAttr('make:migration', 'Generate SQL diff from entities to MySQL schema')]
final class DatabaseMigrationCommand extends Command
{
    use Confirmable;

    public function __construct(
        private Connection     $connection,
        private EntityScanner  $scanner,
        private MigrationGenerator $generator
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $entities = $this->scanner->scanDir(\base_path('app/Entity'));

        $schema   = $this->connection->pdo()->query(
            'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()'
        )->fetchAll(\PDO::FETCH_GROUP|\PDO::FETCH_UNIQUE);

        $sql = $this->generator->diff($entities, $schema);

        if ($sql === '') {
            $this->info('Nothing to migrate - database already matches entities.');
            return self::SUCCESS;
        }

        $file = \base_path('var/migrations/')
            . date('Y_m_d_His') . '_auto.sql';

        \file_put_contents($file, $sql);
        $this->info("Created migration: {$file}");

        return self::SUCCESS;
    }
}