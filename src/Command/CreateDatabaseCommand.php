<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Create the database schema from .env credentials.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('db:create', 'Create the database schema from .env credentials')]
final class CreateDatabaseCommand extends Command
{
    protected function handle(): int
    {
        /** @var array{default: string, connections: array<string, array<string, mixed>>} $cfg */
        $cfg  = require base_path('config/database.php');
        $conn = $cfg['connections'][$cfg['default']] ?? [];

        $dsn     = isset($conn['dsn']) && is_string($conn['dsn']) ? $conn['dsn'] : '';
        $appUser = isset($conn['username']) && is_string($conn['username']) ? $conn['username'] : 'root';
        $appPass = isset($conn['password']) && is_string($conn['password']) ? $conn['password'] : '';

        if (!str_starts_with($dsn, 'mysql:')) {
            $this->comment('db:create skipped — driver is not MySQL.');

            return self::SUCCESS;
        }

        // Parse host/port/dbname from DSN
        $parts = [];

        foreach (explode(';', substr($dsn, 6)) as $chunk) {
            if ($chunk === '') {
                continue;
            }

            [$k, $v] = array_map('trim', explode('=', $chunk, 2));
            $parts[$k] = $v;
        }

        $host = $parts['host'] ?? '127.0.0.1';
        $port = $parts['port'] ?? 3306;
        $db   = $parts['dbname'] ?? 'app';

        $dsnTpl = 'mysql:host=%s;port=%s;charset=utf8mb4';

        // Connect
        try {
            $pdo = new \PDO(
                sprintf($dsnTpl, $host, $port),
                $appUser,
                $appPass,
                [
                    \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ],
            );
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'getaddrinfo')) {
                $pdo = new \PDO(
                    sprintf($dsnTpl, '127.0.0.1', $port),
                    $appUser,
                    $appPass,
                    [
                        \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    ],
                );
            } else {
                $this->error("Connection failed: {$e->getMessage()}");

                return self::FAILURE;
            }
        }

        // Create schema
        try {
            $pdo->exec(sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $db,
            ));

            $this->info("✅ Schema \"{$db}\" is ready on {$host}:{$port}.");
        } catch (\PDOException $e) {
            if (in_array($e->errorInfo[1] ?? null, [1044, 1045], true)) {
                $this->warn(
                    "App user \"{$appUser}\" lacks CREATE DATABASE; " .
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
