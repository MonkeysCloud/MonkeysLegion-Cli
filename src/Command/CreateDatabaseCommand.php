<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cli\Concerns\Confirmable;
use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;

/**
 * db:create
 * -------
 * Creates the database defined in config/database.php if it doesn't exist.
 */
#[CommandAttr('db:create', 'Create the database if it does not exist')]
final class CreateDatabaseCommand extends Command
{
    use Confirmable;

    protected function handle(): int
    {
        $cfg   = require base_path('config/database.php');
        $conn  = $cfg['connections'][$cfg['default']] ?? [];

        $dsn     = $conn['dsn']      ?? '';
        $appUser = $conn['username'] ?? 'root';
        $appPass = $conn['password'] ?? '';

        // only MySQL connections are supported
        if (! str_starts_with($dsn, 'mysql:')) {
            $this->info('db:create skipped: driver not MySQL.');
            return self::SUCCESS;
        }

        $rootUser = $_ENV['DB_ROOT_USER']     ?? 'root';
        $rootPass = $_ENV['DB_ROOT_PASSWORD'] ?? ($_ENV['MYSQL_ROOT_PASSWORD'] ?? '');

        // parse out host/port/dbname
        $dsnBody = preg_replace('/^[^:]+:/', '', $dsn);
        $parts   = [];
        foreach (explode(';', $dsnBody) as $chunk) {
            if ($chunk === '') continue;
            [$k, $v] = array_map('trim', explode('=', $chunk, 2));
            $parts[$k] = $v;
        }

        $host = $parts['host']   ?? '127.0.0.1';
        $port = $parts['port']   ?? 3306;
        $name = $parts['dbname'] ?? 'app';

        // connect as root (so we can CREATE DATABASE)
        $dsnTpl = 'mysql:host=%s;port=%s;charset=utf8mb4';
        try {
            $pdo = new \PDO(
                sprintf($dsnTpl, $host, $port),
                $rootUser,
                $rootPass,
                [
                    \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]
            );
        } catch (\PDOException $e) {
            // fallback on localhost if container name isn’t resolvable
            if (str_contains($e->getMessage(), 'getaddrinfo')) {
                $pdo = new \PDO(
                    sprintf($dsnTpl, '127.0.0.1', $port),
                    $rootUser,
                    $rootPass,
                    [
                        \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    ]
                );
            } else {
                $this->error("Failed to connect as root to {$host}:{$port} — " . $e->getMessage());
                return self::FAILURE;
            }
        }

        $sql = sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $name
        );
        $pdo->exec($sql);

        $this->info("Database “{$name}” is ready on {$host}:{$port}.");
        return self::SUCCESS;
    }

}