<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Console;

use PDO;
use PDOStatement;

abstract class Command
{
    public const int SUCCESS = 0;
    public const int FAILURE = 1;

    /** Override in children */
    abstract protected function handle(): int;

    /* ---------- helpers -------------------------------------------------- */

    protected function info(string $msg): void
    {
        $this->write($msg, 32);
    }

    protected function line(string $msg): void
    {
        $this->write($msg);
    }

    protected function error(string $msg): void
    {
        $this->write($msg, 31);
    }

    public function __construct() {}

    private function write(string $msg, int $color = 0): void
    {
        $colorized = $color ? "\033[{$color}m{$msg}\033[0m" : $msg;
        fwrite($color === 31 ? STDERR : STDOUT, $colorized . PHP_EOL);
    }

    protected function safeQuery(PDO $pdo, string $sql): PDOStatement
    {
        $stmt = $pdo->query($sql);
        if (!$stmt) {
            $error = is_string($pdo->errorInfo()[2] ?? null) ? $pdo->errorInfo()[2] : 'Unknown error';
            throw new \RuntimeException('Query failed: ' . $error);
        }

        return $stmt;
    }

    /**
     * Prompts the user with a question and retrieves their input.
     *
     * @param string $q The question to prompt the user with.
     * @return string The user's input after trimming whitespace.
     */
    protected function ask(string $prompt): string
    {
        return function_exists('readline')
            ? trim(readline("$prompt ") ?: '')
            : trim(fgets(STDIN) ?: '');
    }

    /* ---------- runtime entry point -------------------------------------- */

    public function __invoke(): int
    {
        return $this->handle();
    }
}
