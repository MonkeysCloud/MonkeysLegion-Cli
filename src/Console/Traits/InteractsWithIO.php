<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Console\Traits;

/**
 * Trait InteractsWithIO
 *
 * Provides methods for interactive terminal input and prompts.
 */
trait InteractsWithIO
{
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
     * Works natively across Linux, macOS, and Windows.
     */
    protected function secret(string $question): string
    {
        // --- WINDOWS IMPLEMENTATION ---
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            fwrite(STDOUT, "\033[33m{$question}\033[0m ");

            // Using PowerShell's native secure string input method
            $exe = 'powershell -Command "$pword = read-host -AsSecureString; ' .
                '[Runtime.InteropServices.Marshal]::PtrToStringAuto(' .
                '[Runtime.InteropServices.Marshal]::SecureStringToBSTR($pword))"';

            $value = shell_exec($exe);
            fwrite(STDOUT, PHP_EOL);

            return trim($value ?: '');
        }

        // --- UNIX (LINUX / MACOS) IMPLEMENTATION ---
        if ($this->supportsStty()) {
            // Check if we are on macOS vs Linux to handle stty -g formatting safely
            $isMac = str_contains(strtolower(PHP_OS), 'darwin');
            $sttyMode = null;

            if ($isMac) {
                // macOS handles stty -g strings cleanly via standard arguments
                $sttyMode = shell_exec('stty -g');
            }

            // Disable echo
            shell_exec('stty -echo');

            // Print the prompt safely
            fwrite(STDOUT, "\033[33m{$question}\033[0m ");

            // Read the hidden user input
            $value = trim(fgets(STDIN) ?: '');

            // Restore terminal state based on what's supported
            if ($isMac && $sttyMode !== null) {
                shell_exec('stty ' . escapeshellarg(trim($sttyMode)));
            } else {
                // Linux fallback: Natively re-enable echo directly without string parsing
                shell_exec('stty echo');
            }

            fwrite(STDOUT, PHP_EOL);

            return $value;
        }

        // --- ULTIMATE FALLBACK ---
        // If neither Windows nor working TTY controls are found, input stays visible
        fwrite(STDOUT, "\033[33m{$question}\033[0m ");
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

    private function supportsStty(): bool
    {
        if (!function_exists('shell_exec')) {
            return false;
        }

        // Test if stty is present and interacting with a valid TTY stream
        // without printing, reading, or caching configuration buffers.
        exec('stty 2>/dev/null', $output, $exitCode);

        return $exitCode === 0;
    }
}
