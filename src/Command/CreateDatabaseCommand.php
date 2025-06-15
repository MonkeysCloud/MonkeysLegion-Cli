<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cli\Concerns\Confirmable;
use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;

#[CommandAttr('db:create', 'Create the database if it does not exist')]
final class CreateDatabaseCommand extends Command
{
    use Confirmable;

    protected function handle(): int
    {
        // your config/database.php returns the same array used by Connection
        $cfg = require base_path('config/database.php');

        $host = $cfg['host'] ?? '127.0.0.1';
        $port = $cfg['port'] ?? 3306;
        $name = $cfg['dbname'] ?? 'app';
        $user = $cfg['user'] ?? 'root';
        $pass = $cfg['pass'] ?? '';

        // 1 Connect *without* selecting the schema
        $pdo = new \PDO(
            "mysql:host={$host};port={$port};charset=utf8mb4",
            $user,
            $pass,
            [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]
        );

        // 2 Create database if missing
        $sql = "CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        $pdo->exec($sql);

        $this->info("Database `{$name}` is ready.");

        return self::SUCCESS;
    }
}