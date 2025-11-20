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

    /**
     * Get the value of a command-line option/flag.
     * 
     * Supports multiple formats:
     * - `--stage=dev` returns "dev"
     * - `--stage dev` returns "dev"
     * - `--verbose` returns true (boolean flag)
     * - `-v` returns true (short flag)
     * 
     * Examples:
     * ```php
     * $stage = $this->option('stage');           // --stage=dev or --stage dev
     * $verbose = $this->option('verbose');       // --verbose
     * $force = $this->option('force', false);    // --force (with default)
     * $env = $this->option('env', 'production'); // --env=staging (with default)
     * ```
     *
     * @param string $name The option name (without dashes)
     * @param mixed $default Default value if option is not present
     * @return mixed The option value, true for boolean flags, or default if not found
     */
    protected function option(string $name, mixed $default = null): mixed
    {
        global $argv;

        if (!is_array($argv)) {
            return $default;
        }

        $longFlag = "--{$name}";
        $shortFlag = "-" . substr($name, 0, 1);

        foreach ($argv as $i => $arg) {
            // Format: --option=value
            if (str_starts_with($arg, "{$longFlag}=")) {
                return substr($arg, strlen($longFlag) + 1);
            }

            // Format: --option value (next argument is the value)
            if ($arg === $longFlag) {
                // Check if next argument exists and is not another flag
                if (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
                    return $argv[$i + 1];
                }
                // Boolean flag (no value)
                return true;
            }

            // Short flag format: -o value or -o (boolean)
            if ($arg === $shortFlag) {
                if (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
                    return $argv[$i + 1];
                }
                return true;
            }
        }

        return $default;
    }

    /**
     * Check if a flag/option is present in the command line.
     * 
     * Examples:
     * ```php
     * if ($this->hasOption('force')) {
     *     // --force was passed
     * }
     * 
     * if ($this->hasOption('dump')) {
     *     // --dump was passed
     * }
     * ```
     *
     * @param string $name The option name (without dashes)
     * @return bool True if the option is present, false otherwise
     */
    protected function hasOption(string $name): bool
    {
        global $argv;

        if (!is_array($argv)) {
            return false;
        }

        $longFlag = "--{$name}";
        $shortFlag = "-" . substr($name, 0, 1);

        foreach ($argv as $arg) {
            if ($arg === $longFlag || str_starts_with($arg, "{$longFlag}=")) {
                return true;
            }
            if ($arg === $shortFlag) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all options passed to the command.
     * 
     * Returns an associative array of all options with their values.
     * Boolean flags will have the value `true`.
     *
     * @return array<string, mixed>
     */
    protected function allOptions(): array
    {
        global $argv;

        if (!is_array($argv)) {
            return [];
        }

        $options = [];

        for ($i = 0; $i < count($argv); $i++) {
            $arg = $argv[$i];

            // Skip non-option arguments
            if (!str_starts_with($arg, '-')) {
                continue;
            }

            // Format: --option=value
            if (preg_match('/^--([^=]+)=(.+)$/', $arg, $matches)) {
                $options[$matches[1]] = $matches[2];
                continue;
            }

            // Format: --option or -o
            if (str_starts_with($arg, '--')) {
                $name = substr($arg, 2);
                // Check if next arg is a value
                if (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
                    $options[$name] = $argv[$i + 1];
                    $i++; // Skip next arg since we consumed it
                } else {
                    $options[$name] = true;
                }
            } elseif (str_starts_with($arg, '-') && strlen($arg) === 2) {
                $name = substr($arg, 1);
                // Check if next arg is a value
                if (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
                    $options[$name] = $argv[$i + 1];
                    $i++; // Skip next arg since we consumed it
                } else {
                    $options[$name] = true;
                }
            }
        }

        return $options;
    }

    /* ---------- runtime entry point -------------------------------------- */

    public function __invoke(): int
    {
        return $this->handle();
    }
}
