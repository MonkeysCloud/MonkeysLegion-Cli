<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Application;

use MonkeysLegion\Cli\CliKernel;

/**
 * MLRunner: The developer-facing entry point for executing CLI commands programmatically.
 */
class MLRunner
{
    private static ?CliKernel $kernel = null;

    /**
     * Initialize the static runner with the Kernel instance.
     * Usually called once in your bootstrap or service provider.
     * Subsequent calls are ignored to keep boot idempotent.
     */
    public static function boot(CliKernel $kernel): void
    {
        if (self::$kernel !== null) {
            return;
        }

        self::$kernel = $kernel;
    }

    /**
     * The standard way to run a string command.
     * Handles the 'ml ' prefix removal and argument parsing.
     */
    public static function call(string $command): int
    {
        // Strip 'ml ' if the dev included it out of habit
        if (str_starts_with($command, 'ml ')) {
            $command = substr($command, 3);
        }

        // Regex to split by space but preserve quoted strings
        $args = preg_split('/\s+(?=(?:[^"]*"[^"]*")*[^"]*$)/', $command);
        $args = array_map(fn($a) => trim($a, " \"'"), $args);

        // Kernel expects $argv style, where [0] is the script name. 
        // We prepend a dummy name so the Kernel picks up the command at index [1].
        array_unshift($args, 'ml');

        return self::$kernel->run($args);
    }

    /**
     * Run a command and return the output as a string instead of printing it.
     */
    public static function capture(string $command): string
    {
        ob_start();
        self::call($command);
        return trim(ob_get_clean() ?: '');
    }

    /**
     * Run a command and return a detailed result array (code + output).
     * 
     * @return array{exit_code: int, output: string, success: bool}
     */
    public static function inspect(string $command): array
    {
        ob_start();
        $exitCode = self::call($command);
        $output = ob_get_clean();

        return [
            'exit_code' => $exitCode,
            'output'    => $output,
            'success'   => $exitCode === 0
        ];
    }

    /**
     * Execute using a raw array of arguments (e.g., from a web request or global $argv).
     */
    public static function raw(array $args): int
    {
        // Ensure index [0] exists for kernel compatibility
        if (!isset($args[0])) {
            array_unshift($args, 'ml');
        }

        return self::$kernel->run($args);
    }

    /**
     * Run a command and immediately terminate the PHP process with the resulting exit code.
     * Useful for the main entry point (bin/ml) file.
     */
    public static function terminate(string $command): never
    {
        exit(self::call($command));
    }

    /**
     * Run a command silently (discards all output).
     */
    public static function silent(string $command): int
    {
        ob_start();
        $exitCode = self::call($command);
        ob_end_clean();

        return $exitCode;
    }
}
