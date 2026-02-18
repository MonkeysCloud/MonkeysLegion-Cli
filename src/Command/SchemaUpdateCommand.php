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
                        // ignore "already exists" / duplicate-column errors
                        // MySQL: 42S21 (dup column), 42S01 (dup table)
                        // PostgreSQL: 42P07 (dup table), 42701 (dup column)
                        if (in_array($e->getCode(), ['42S21', '42S01', '42P07', '42701'], true)) {
                            $this->line('Skipped: ' . substr($stmt, 0, 50) . '…');
                            continue;
                        }

                        // PostgreSQL 2BP01: dependent objects still exist
                        // Retry DROP statements with CASCADE; other statements are fatal
                        if ($e->getCode() === '2BP01') {
                            if (preg_match('/^\s*DROP\s/i', $stmt)) {
                                $cascadeStmt = rtrim($stmt) . ' CASCADE';
                                $pdo->exec($cascadeStmt);
                                $this->line('Retried with CASCADE: ' . substr($stmt, 0, 50) . '…');
                                continue;
                            }
                            // Non-DROP 2BP01 → surface the error so ordering can be fixed
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
     * Introspect the database schema into an array:
     *  [ tableName => [ columnName => columnInfoArray, … ], … ]
     *
     * Returns the same structure for both MySQL and PostgreSQL so that
     * MigrationGenerator::diff() can consume it identically.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function introspectSchema(): array
    {
        $pdo    = $this->db->pdo();
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            return $this->introspectPgsql($pdo);
        }

        return $this->introspectMysql($pdo);
    }

    /**
     * MySQL introspection via SHOW TABLES / SHOW COLUMNS.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function introspectMysql(\PDO $pdo): array
    {
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
                $type = (string) ($col['Type'] ?? '');
                $schema[$table][$col['Field']] = [
                    'type'     => $type,
                    'nullable' => (strtoupper($col['Null'] ?? '') === 'YES'),
                    'default'  => $col['Default'] ?? null,
                    'length'   => preg_match('/\((.*)\)/', $type, $matches) ? $matches[1] : null,
                ];
            }
        }
        return $schema;
    }

    /**
     * PostgreSQL introspection via pg_tables / information_schema.columns.
     *
     * Column rows are aliased to the same keys that MySQL returns
     * (Field, Type, Null, Default, Extra) so the diff layer receives a
     * uniform structure.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function introspectPgsql(\PDO $pdo): array
    {
        // Resolve the active schema from search_path rather than hardcoding 'public'
        $schemaStmt = $pdo->query('SELECT current_schema()');
        $pgSchema   = $schemaStmt !== false ? ($schemaStmt->fetchColumn() ?: 'public') : 'public';

        $tablesStmt = $pdo->prepare(
            'SELECT tablename FROM pg_tables WHERE schemaname = :schema'
        );
        $tablesStmt->execute(['schema' => $pgSchema]);
        /** @var list<string> $tables */
        $tables = $tablesStmt->fetchAll(\PDO::FETCH_COLUMN);

        $colSql = <<<'SQL'
            SELECT column_name     AS "Field",
                   data_type       AS "Type",
                   is_nullable     AS "Null",
                   column_default  AS "Default",
                   CASE WHEN column_default LIKE 'nextval%%' THEN 'auto_increment' ELSE '' END AS "Extra"
              FROM information_schema.columns
             WHERE table_schema = :schema
               AND table_name  = :table
             ORDER BY ordinal_position
        SQL;

        $colsStmt = $pdo->prepare($colSql);

        $schema = [];
        foreach ($tables as $table) {
            $colsStmt->execute(['schema' => $pgSchema, 'table' => $table]);
            /** @var list<array<string, mixed>> $cols */
            $cols = $colsStmt->fetchAll(\PDO::FETCH_ASSOC);

            $schema[$table] = [];
            foreach ($cols as $col) {
                if (!isset($col['Field']) || !is_string($col['Field'])) {
                    throw new \RuntimeException("Invalid column definition in table '{$table}'");
                }
                $type = (string) ($col['Type'] ?? '');
                $schema[$table][$col['Field']] = [
                    'type'     => $type,
                    'nullable' => (strtoupper($col['Null'] ?? '') === 'YES'),
                    'default'  => $col['Default'] ?? null,
                    'length'   => preg_match('/\((.*)\)/', $type, $matches) ? $matches[1] : null,
                ];
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

        if (str_starts_with($dsn, 'pgsql:')) {
            return $this->createDatabasePgsql($dsn, $appUser, $appPass);
        }

        if (str_starts_with($dsn, 'mysql:')) {
            return $this->createDatabaseMysql($dsn, $appUser, $appPass);
        }

        $this->error('Database creation skipped – unsupported driver.');
        return false;
    }

    /**
     * Create a PostgreSQL database via the 'postgres' maintenance DB.
     */
    private function createDatabasePgsql(string $dsn, string $appUser, string $appPass): bool
    {
        // Parse all DSN options so we preserve sslmode, options, etc.
        $parts = [];
        foreach (explode(';', substr($dsn, 6)) as $chunk) {
            if ($chunk === '') continue;
            [$k, $v] = array_map('trim', explode('=', $chunk, 2));
            $parts[$k] = $v;
        }
        $db = $parts['dbname'] ?? 'app';

        // Validate identifier: only word chars, digits, hyphens, dots allowed
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_\-\.]*$/', $db)) {
            $this->error("Invalid database name: '{$db}'");
            return false;
        }

        // Rebuild DSN swapping dbname to 'postgres' while keeping every other option
        $parts['dbname'] = 'postgres';
        $maintenanceDsn  = 'pgsql:' . implode(';', array_map(
            static fn(string $k, string $v): string => "{$k}={$v}",
            array_keys($parts),
            array_values($parts)
        ));

        try {
            $pdo = new \PDO(
                $maintenanceDsn,
                $appUser,
                $appPass,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );

            // Check if database already exists
            $stmt = $pdo->prepare("SELECT 1 FROM pg_database WHERE datname = :db");
            $stmt->execute(['db' => $db]);

            $created = false;
            if (!$stmt->fetch()) {
                // Safe: $db validated above against strict identifier regex
                $quoted = '"' . str_replace('"', '""', $db) . '"';
                $pdo->exec("CREATE DATABASE {$quoted} ENCODING 'UTF8'");
                $created = true;
            }

            if ($created) {
                $this->info("✔️  Database '{$db}' created successfully.");
            } else {
                $this->info("ℹ️  Database '{$db}' already exists, skipping creation.");
            }
            return true;
        } catch (\PDOException $e) {
            $this->error("Failed to create database: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Create a MySQL database (original logic preserved).
     */
    private function createDatabaseMysql(string $dsn, string $appUser, string $appPass): bool
    {
        $parts = [];
        foreach (explode(';', substr($dsn, 6)) as $chunk) {
            if ($chunk === '') continue;
            [$k, $v] = array_map('trim', explode('=', $chunk, 2));
            $parts[$k] = $v;
        }
        $host = $parts['host'] ?? '127.0.0.1';
        $port = $parts['port'] ?? 3306;
        $db   = $parts['dbname'] ?? 'app';

        $dsnTpl = 'mysql:host=%s;port=%s;charset=utf8mb4';

        try {
            $pdo = new \PDO(
                sprintf($dsnTpl, $host, $port),
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
        } catch (\PDOException $e) {
            if ($host !== '127.0.0.1') {
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
