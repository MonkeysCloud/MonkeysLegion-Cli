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
        /** -----------------------------------------------
         * 1. Load connection config (same as Connection.php)
         * ---------------------------------------------- */
        $cfg   = require base_path('config/database.php');
        $conn  = $cfg['connections'][$cfg['default']] ?? [];

        $dsn   = $conn['dsn']      ?? '';
        $user  = $conn['username'] ?? 'root';
        $pass  = $conn['password'] ?? '';

        /* -----------------------------------------------
         * 2. Parse the DSN → host / port / dbname
         * ---------------------------------------------- */
        $parts = [];
        foreach (explode(';', $dsn) as $chunk) {
            if ($chunk === '') continue;
            [$k, $v] = array_map('trim', explode('=', $chunk, 2));
            $parts[$k] = $v;
        }

        $host = $parts['host']   ?? '127.0.0.1';
        $port = $parts['port']   ?? 3306;
        $name = $parts['dbname'] ?? 'app';

        /* -----------------------------------------------
         * 3. Connect to MySQL *without* selecting database
         * ---------------------------------------------- */
        $pdo = new \PDO(
            sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port),
            $user,
            $pass,
            [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]
        );

        /* -----------------------------------------------
         * 4. Create schema if missing
         * ---------------------------------------------- */
        $sql = sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $name
        );
        $pdo->exec($sql);

        $this->info("Database “{$name}” is ready on {$host}:{$port}.");

        return self::SUCCESS;
    }
}