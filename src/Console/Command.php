<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Console;

use MonkeysLegion\Cli\Console\Traits\Cli;
use PDO;
use PDOStatement;

abstract class Command
{
    use Cli;

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

    /**
     * Get a command line argument by index.
     * Index 0 returns the argument after the command name.
     * 
     * For example, in "php ml mail:work queue_name":
     * - argument(0) returns "queue_name"
     * - argument(1) returns the next argument, etc.
     *
     * @param int $index The argument index (0-based, relative to command)
     * @return string|null The argument value or null if not set
     */
    protected function argument(int $index): ?string
    {
        global $argv;

        if (!is_array($argv)) {
            return null;
        }

        // Find where the command starts (after "php ml")
        // Typically: argv[0] = "ml", argv[1] = "command:name", argv[2+] = arguments
        // For "php ml mail:work queue_name": argv[0]="ml", argv[1]="mail:work", argv[2]="queue_name"
        $commandIndex = 1; // The command is at index 1 after the script name
        $argumentIndex = $commandIndex + 1 + $index; // Skip script and command, then apply index

        return $argv[$argumentIndex] ?? null;
    }

    /* ---------- runtime entry point -------------------------------------- */

    public function __invoke(): int
    {
        return $this->handle();
    }
}
