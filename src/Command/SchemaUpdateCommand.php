<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Database\Contracts\ConnectionInterface;
use MonkeysLegion\Entity\Scanner\EntityScanner;
use MonkeysLegion\Migration\MigrationGenerator;
use ReflectionException;

#[CommandAttr(
    'schema:update',
    'Compare entities → database and apply missing tables/columns (use --dump or --force)'
)]
final class SchemaUpdateCommand extends Command
{
    public function __construct(
        private ConnectionInterface $db,
        private EntityScanner $scanner,
        private MigrationGenerator $generator
    ) {
        parent::__construct();
    }

    /**
     * Handle the command.
     *
     * @return int
     * @throws ReflectionException
     */
    public function handle(): int
    {
        $args = (array) ($_SERVER['argv'] ?? []);
        $dump  = in_array('--dump', $args, true);
        $force = in_array('--force', $args, true);

        // Check if database exists first
        if (!$this->checkDatabaseExists()) {
            $response = $this->ask('Database does not exist. Create it? (y/N)');
            if (strtolower(trim($response)) !== 'y' && strtolower(trim($response)) !== 'yes') {
                $this->error('Aborted. Database creation declined.');
                return self::FAILURE;
            }

            if (!$this->createDatabase()) {
                $this->error('Failed to create database.');
                return self::FAILURE;
            }
        }

        // 1) Scan your Entity classes directory
        $this->line('🔍 Scanning entities…');
        $entities = $this->scanner->scanDir(base_path('app/Entity')); // ← use scanDir()

        // 2) Read current DB schema
        $this->line('🔍 Reading current database schema…');
        $schema = $this->introspectSchema();

        // 3) Compute diff
        $sql = trim($this->generator->diff($entities, $schema));
        if ($sql === '') {
            $this->info('✔️  Schema is already up to date.');
            return self::SUCCESS;
        }

        // 4) Dump if requested
        if ($dump) {
            $this->line("\n-- Generated SQL:\n" . $sql . "\n");
        }

        // 5) Apply if forced
        if ($force) {
            $pdo = $this->db->pdo();

            try {
                // split on “;” followed by newline / EOF
                $stmts = preg_split('/;\\s*(?=\\R|$)/', trim($sql)) ?: [];
                foreach ($stmts as $stmt) {
                    $stmt = trim($stmt);
                    if ($stmt === '') {
                        continue;
                    }
                    try {
                        $pdo->exec($stmt);
                    } catch (\PDOException $e) {
                        // ignore “already exists” / duplicate-column errors
                        if (in_array($e->getCode(), ['42S21', '42S01'], true)) {
                            $this->line('Skipped: ' . substr($stmt, 0, 50) . '…');
                            continue;
                        }
                        throw $e;   // anything else is fatal
                    }
                }

                $this->info('✅  Schema updated successfully.');
            } catch (\PDOException $e) {
                // only roll back if a txn is still open (unlikely with DDL)
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $this->error('❌  Failed: ' . $e->getMessage());
                return self::FAILURE;
            }
        } elseif (! $dump) {
            $this->info('ℹ️  No action taken. Use --dump to preview or --force to apply.');
        }

        return self::SUCCESS;
    }

    /**
     * Introspect the MySQL schema into an array:
     *  [ tableName => [ columnName => columnInfoArray, … ], … ]
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function introspectSchema(): array
    {
        $pdo = $this->db->pdo();

        $tablesStmt = $this->safeQuery($pdo, "SHOW TABLES");
        /** @var list<string> $tables */
        $tables = $tablesStmt->fetchAll(\PDO::FETCH_COLUMN);

        $schema = [];
        foreach ($tables as $table) {
            $colsStmt = $this->safeQuery($pdo, "SHOW COLUMNS FROM `{$table}`");
            /** @var list<array<string, mixed>> $cols */
            $cols = $colsStmt->fetchAll(\PDO::FETCH_ASSOC);

            $schema[$table] = [];
            foreach ($cols as $col) {
                if (!isset($col['Field']) || !is_string($col['Field'])) {
                    throw new \RuntimeException("Invalid column definition in table '{$table}'");
                }
                $schema[$table][$col['Field']] = $col;
            }
        }
        return $schema;
    }

    /**
     * Check if the database exists by attempting to connect to it.
     */
    private function checkDatabaseExists(): bool
    {
        try {
            $this->db->pdo();
            return true;
        } catch (\PDOException $e) {
            // Database doesn't exist or connection failed
            return false;
        }
    }

    /**
     * Create the database using configuration from .env
     */
    private function createDatabase(): bool
    {
        /** 
         * @var array{
         *   default: string,
         *   connections: array<string, array<string, mixed>>
         * } $cfg
         */
        $cfg  = require base_path('config/database.php');
        $conn = $cfg['connections'][$cfg['default']] ?? [];

        $dsn = isset($conn['dsn']) && is_string($conn['dsn']) ? $conn['dsn'] : '';
        $appUser = isset($conn['username']) && is_string($conn['username']) ? $conn['username'] : 'root';
        $appPass = isset($conn['password']) && is_string($conn['password']) ? $conn['password'] : '';

        if (!str_starts_with($dsn, 'mysql:')) {
            $this->error('Database creation skipped – driver not MySQL.');
            return false;
        }

        // Parse DSN to get database name and connection details
        $parts = [];
        foreach (explode(';', substr($dsn, 6)) as $chunk) {
            if ($chunk === '') continue;
            [$k, $v] = array_map('trim', explode('=', $chunk, 2));
            $parts[$k] = $v;
        }
        $host = $parts['host'] ?? '127.0.0.1';
        $port = $parts['port'] ?? 3306;
        $db = $parts['dbname'] ?? 'app';

        $dsnTpl = 'mysql:host=%s;port=%s;charset=utf8mb4';

        try {
            // Connect without specifying database
            $pdo = new \PDO(
                sprintf($dsnTpl, $host, $port),
                $appUser,
                $appPass,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]
            );

            // Create the database
            $pdo->exec(
                sprintf(
                    'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                    $db
                )
            );

            $this->info("✔️  Database '{$db}' created successfully.");
            return true;
        } catch (\PDOException $e) {
            if ($host !== '127.0.0.1') {
                // Retry with localhost
                try {
                    $pdo = new \PDO(
                        sprintf($dsnTpl, '127.0.0.1', $port),
                        $appUser,
                        $appPass,
                        [
                            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                        ]
                    );

                    $pdo->exec(
                        sprintf(
                            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                            $db
                        )
                    );

                    $this->info("✔️  Database '{$db}' created successfully.");
                    return true;
                } catch (\PDOException $retryE) {
                    $this->error("Failed to create database: {$retryE->getMessage()}");
                    return false;
                }
            }

            $this->error("Failed to create database: {$e->getMessage()}");
            return false;
        }
    }
}
