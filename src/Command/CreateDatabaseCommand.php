<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Mlc\Config as MlcConfig;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Create the database schema from MLC config / .env credentials.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('db:create', 'Create the database schema from .env credentials')]
final class CreateDatabaseCommand extends Command
{
    public function __construct(
        private readonly MlcConfig $config,
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $driver = $this->config->getString('database.default', 'mysql') ?? 'mysql';

        if ($driver === 'sqlite') {
            $this->comment('db:create skipped — driver is SQLite.');
            return self::SUCCESS;
        }

        if ($driver !== 'mysql' && $driver !== 'pgsql') {
            $this->comment("db:create skipped — unsupported driver \"{$driver}\".");
            return self::SUCCESS;
        }

        // Read connection config from MLC
        $prefix = "database.connections.{$driver}";
        $host   = $this->config->getString("{$prefix}.host", '127.0.0.1') ?? '127.0.0.1';
        $port   = $this->config->getInt("{$prefix}.port", $driver === 'pgsql' ? 5432 : 3306)
                  ?? ($driver === 'pgsql' ? 5432 : 3306);
        $db     = $this->config->getString("{$prefix}.database", 'ml_skeleton') ?? 'ml_skeleton';
        $user   = $this->config->getString("{$prefix}.username", 'root') ?? 'root';
        $pass   = $this->config->getString("{$prefix}.password", '') ?? '';

        // Build DSN without database name (to create it)
        if ($driver === 'mysql') {
            $dsnTpl = 'mysql:host=%s;port=%d;charset=utf8mb4';
            $createSql = sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $db,
            );
        } else {
            // PostgreSQL
            $dsnTpl = 'pgsql:host=%s;port=%d';
            $createSql = sprintf(
                "SELECT 'CREATE DATABASE \"%s\"' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '%s')",
                $db, $db,
            );
        }

        // Connect
        try {
            $pdo = new \PDO(
                sprintf($dsnTpl, $host, $port),
                $user,
                $pass,
                [
                    \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ],
            );
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'getaddrinfo')) {
                try {
                    $pdo = new \PDO(
                        sprintf($dsnTpl, '127.0.0.1', $port),
                        $user,
                        $pass,
                        [
                            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                        ],
                    );
                } catch (\PDOException $e2) {
                    $this->error("Connection failed: {$e2->getMessage()}");
                    return self::FAILURE;
                }
            } else {
                $this->error("Connection failed: {$e->getMessage()}");
                return self::FAILURE;
            }
        }

        // Create schema
        try {
            $pdo->exec($createSql);
            $this->info("✅ Schema \"{$db}\" is ready on {$host}:{$port}.");
        } catch (\PDOException $e) {
            $code = $e->errorInfo[1] ?? null;
            if (in_array($code, [1044, 1045], true)) {
                $this->warn(
                    "App user \"{$user}\" lacks CREATE DATABASE; " .
                    "ensure schema \"{$db}\" exists or grant the privilege.",
                );
                return self::SUCCESS;
            }

            $this->error("Failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
