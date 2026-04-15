<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Console;

use MonkeysLegion\Cli\Console\Output\ProgressBar;
use MonkeysLegion\Cli\Console\Output\Spinner;
use MonkeysLegion\Cli\Console\Output\TableRenderer;
use MonkeysLegion\Cli\Console\Traits\Cli;
use PDO;
use PDOStatement;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Base command with rich output helpers covering Laravel Artisan,
 * Symfony Console, and MonkeysLegion-exclusive features.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
abstract class Command
{
    use Cli;

    public const int SUCCESS = 0;
    public const int FAILURE = 1;

    /** Override in children. */
    abstract protected function handle(): int;

    public function __construct() {}

    // ── Output helpers ────────────────────────────────────────────

    /** Green info message. */
    protected function info(string $msg): void
    {
        $this->write($msg, 32);
    }

    /** Plain line. */
    protected function line(string $msg): void
    {
        $this->write($msg);
    }

    /** Red error to STDERR. */
    protected function error(string $msg): void
    {
        $this->write($msg, 31, STDERR);
    }

    /** Yellow warning. */
    protected function warn(string $msg): void
    {
        $this->write($msg, 33);
    }

    /** Gray italic comment. */
    protected function comment(string $msg): void
    {
        fwrite(STDOUT, "\033[3;90m{$msg}\033[0m" . PHP_EOL);
    }

    /**
     * Prominent alert box.
     *
     *   ┌──────────────────────┐
     *   │  ⚠  Alert message    │
     *   └──────────────────────┘
     */
    protected function alert(string $msg): void
    {
        $len    = mb_strlen($msg) + 6;
        $border = str_repeat('─', $len);

        fwrite(STDOUT, "\033[33m┌{$border}┐\033[0m" . PHP_EOL);
        fwrite(STDOUT, "\033[33m│\033[0m  ⚠  \033[1;33m{$msg}\033[0m  \033[33m│\033[0m" . PHP_EOL);
        fwrite(STDOUT, "\033[33m└{$border}┘\033[0m" . PHP_EOL);
    }

    /** Blank lines. */
    protected function newLine(int $count = 1): void
    {
        fwrite(STDOUT, str_repeat(PHP_EOL, $count));
    }

    // ── Table output ──────────────────────────────────────────────

    /**
     * Render a table to STDOUT.
     *
     * @param list<string>       $headers Column headers
     * @param list<list<string>> $rows    Data rows
     * @param array<int, string> $align   Per-column alignment ('l', 'r', 'c')
     */
    protected function table(array $headers, array $rows, array $align = []): void
    {
        (new TableRenderer())->render($headers, $rows, $align);
    }

    // ── Interactive prompts ───────────────────────────────────────

    /** Ask a question and return the answer. */
    protected function ask(string $prompt): string
    {
        return function_exists('readline')
            ? trim(readline("\033[33m{$prompt}\033[0m ") ?: '')
            : trim(fgets(STDIN) ?: '');
    }

    /**
     * Confirm a yes/no question.
     *
     * @param bool $default Default when user presses Enter
     */
    protected function confirm(string $question, bool $default = false): bool
    {
        $hint    = $default ? 'Y/n' : 'y/N';
        $answer  = strtolower(trim($this->ask("{$question} [{$hint}]")));

        if ($answer === '') {
            return $default;
        }

        return in_array($answer, ['y', 'yes'], true);
    }

    /**
     * Present numbered choices.
     *
     * @param list<string> $choices
     * @return string The selected choice value
     */
    protected function choice(string $question, array $choices, int $default = 0): string
    {
        fwrite(STDOUT, "\033[33m{$question}\033[0m" . PHP_EOL);

        foreach ($choices as $i => $choice) {
            $marker = $i === $default ? "\033[32m▸\033[0m" : ' ';
            fwrite(STDOUT, "  {$marker} \033[36m[{$i}]\033[0m {$choice}" . PHP_EOL);
        }

        $input = trim($this->ask("Choice [{$default}]:"));
        $index = $input === '' ? $default : (int) $input;

        if (!isset($choices[$index])) {
            $this->error("Invalid choice: {$input}");

            return $this->choice($question, $choices, $default);
        }

        return $choices[$index];
    }

    /**
     * Hidden input (for passwords).
     * Falls back to readline if stty is unavailable.
     */
    protected function secret(string $question): string
    {
        fwrite(STDOUT, "\033[33m{$question}\033[0m ");

        if ($this->supportsStty()) {
            $sttyMode = shell_exec('stty -g') ?: '';
            shell_exec('stty -echo');
            $value = trim(fgets(STDIN) ?: '');
            shell_exec('stty ' . trim($sttyMode));
            fwrite(STDOUT, PHP_EOL);

            return $value;
        }

        // Fallback — input is visible
        return trim(fgets(STDIN) ?: '');
    }

    /**
     * Suggest completions while typing (fuzzy).
     *
     * @param list<string> $options Available completions
     */
    protected function anticipate(string $question, array $options, string $default = ''): string
    {
        if ($options !== []) {
            $hint = implode(', ', array_slice($options, 0, 5));
            if (count($options) > 5) {
                $hint .= ', …';
            }
            fwrite(STDOUT, "\033[90m  Suggestions: {$hint}\033[0m" . PHP_EOL);
        }

        $answer = $this->ask($question . ($default !== '' ? " [{$default}]" : ''));

        return $answer !== '' ? $answer : $default;
    }

    // ── Progress bar ──────────────────────────────────────────────

    private ?ProgressBar $progressBar = null;

    /**
     * Start a progress bar.
     */
    protected function progressStart(int $total, string $label = ''): void
    {
        $this->progressBar = new ProgressBar($total, label: $label);
        $this->progressBar->start();
    }

    /**
     * Advance the active progress bar.
     */
    protected function progressAdvance(int $steps = 1): void
    {
        $this->progressBar?->advance($steps);
    }

    /**
     * Finish the active progress bar.
     */
    protected function progressFinish(): void
    {
        $this->progressBar?->finish();
        $this->progressBar = null;
    }

    // ── Spinner ───────────────────────────────────────────────────

    /**
     * Run a callable with an animated spinner.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    protected function spinner(string $message, callable $callback): mixed
    {
        return (new Spinner($message))->run($callback);
    }

    // ── Arguments & options ───────────────────────────────────────

    /**
     * Get a positional argument by index (0-based after command name).
     */
    protected function argument(int $index): ?string
    {
        global $argv;

        if (!is_array($argv)) {
            return null;
        }

        // argv[0] = script, argv[1] = command, argv[2+] = arguments
        $pos = 2 + $index;

        return $argv[$pos] ?? null;
    }

    /**
     * Get a named option value.
     *
     * Supports: `--name=value`, `--name value`, `--flag`, `-n value`, `-n`
     */
    protected function option(string $name, mixed $default = null): mixed
    {
        global $argv;

        if (!is_array($argv)) {
            return $default;
        }

        $long  = "--{$name}";
        $short = '-' . $name[0];

        foreach ($argv as $i => $arg) {
            // --option=value
            if (str_starts_with($arg, "{$long}=")) {
                return substr($arg, strlen($long) + 1);
            }

            // --option [value] or --flag
            if ($arg === $long) {
                if (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
                    return $argv[$i + 1];
                }

                return true;
            }

            // -o [value] or -o
            if ($arg === $short) {
                if (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
                    return $argv[$i + 1];
                }

                return true;
            }
        }

        return $default;
    }

    /**
     * Check if an option/flag is present.
     */
    protected function hasOption(string $name): bool
    {
        global $argv;

        if (!is_array($argv)) {
            return false;
        }

        $long  = "--{$name}";
        $short = '-' . $name[0];

        foreach ($argv as $arg) {
            if ($arg === $long || str_starts_with($arg, "{$long}=") || $arg === $short) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all options as key-value pairs.
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

        for ($i = 0, $c = count($argv); $i < $c; $i++) {
            $arg = $argv[$i];

            if (!str_starts_with($arg, '-')) {
                continue;
            }

            // --option=value
            if (preg_match('/^--([^=]+)=(.+)$/', $arg, $m)) {
                $options[$m[1]] = $m[2];
                continue;
            }

            // --option or -o
            $name = str_starts_with($arg, '--') ? substr($arg, 2) : substr($arg, 1);

            if (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
                $options[$name] = $argv[++$i];
            } else {
                $options[$name] = true;
            }
        }

        return $options;
    }

    // ── PDO helper ────────────────────────────────────────────────

    /**
     * Execute a query with error handling.
     */
    protected function safeQuery(PDO $pdo, string $sql): PDOStatement
    {
        $stmt = $pdo->query($sql);

        if (!$stmt) {
            $error = is_string($pdo->errorInfo()[2] ?? null) ? $pdo->errorInfo()[2] : 'Unknown error';
            throw new \RuntimeException('Query failed: ' . $error);
        }

        return $stmt;
    }

    // ── Runtime ───────────────────────────────────────────────────

    public function __invoke(): int
    {
        return $this->handle();
    }

    // ── Internal ──────────────────────────────────────────────────

    /**
     * Write a colored message to a stream.
     *
     * @param resource $stream
     */
    private function write(string $msg, int $color = 0, mixed $stream = null): void
    {
        $stream    ??= STDOUT;
        $colorized   = $color > 0 ? "\033[{$color}m{$msg}\033[0m" : $msg;
        fwrite($stream, $colorized . PHP_EOL);
    }

    private function supportsStty(): bool
    {
        return function_exists('shell_exec')
            && is_string(shell_exec('stty 2>&1'))
            && !str_contains(shell_exec('stty 2>&1') ?: '', 'not a terminal');
    }
}
