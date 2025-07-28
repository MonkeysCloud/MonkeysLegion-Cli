<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cli\Concerns\Confirmable;
use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;

/**
 * db:create
 * ---------
 * Ensure the schema exists using ONLY app-level credentials from .env.
 */
#[CommandAttr('db:create', 'Create the schema using .env credentials')]
final class CreateDatabaseCommand extends Command
{
    use Confirmable;

    public function handle(): int
    {
        /* ── 1. load DSN / creds from config (.env already parsed there) ── */
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
            $this->info('db:create skipped – driver not MySQL.');
            return self::SUCCESS;
        }

        /* ── 2. pull  host / port / dbname from DSN ────────────────────── */
        $parts = [];
        foreach (explode(';', substr($dsn, 6)) as $chunk) {  // strip "mysql:"
            if ($chunk === '') continue;
            [$k, $v] = array_map('trim', explode('=', $chunk, 2));
            $parts[$k] = $v;
        }
        $host = $parts['host']   ?? '127.0.0.1';
        $port = $parts['port']   ?? 3306;
        $db   = $parts['dbname'] ?? 'app';

        $dsnTpl = 'mysql:host=%s;port=%s;charset=utf8mb4';

        /* ── 3. connect with app user (retry on 127.0.0.1 for host setups) ─ */
        try {
            $pdo = new \PDO(
                sprintf($dsnTpl, $host, $port),
                $appUser,
                $appPass,
                [
                    \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]
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
                    ]
                );
            } else {
                $this->error("Connection failed: {$e->getMessage()}");
                return self::FAILURE;
            }
        }

        /* ── 4. try to create the schema (harmless if it exists) ───────── */
        try {
            $pdo->exec(
                sprintf(
                    'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                    $db
                )
            );
            $this->info("✔️  Schema “{$db}” is ready on {$host}:{$port}.");
        } catch (\PDOException $e) {
            // no CREATE privilege
            if (in_array($e->errorInfo[1] ?? null, [1044, 1045], true)) {
                $this->line(
                    "⚠️  App user “{$appUser}” lacks CREATE DATABASE; " .
                        "ensure schema “{$db}” exists or grant the privilege."
                );
                // treat this as *non-fatal* so other commands can proceed
                return self::SUCCESS;
            }

            $this->error("Failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
