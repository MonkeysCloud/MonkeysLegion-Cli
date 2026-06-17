<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Console\Traits;

/**
 * Trait HasArgumentsAndOptions
 *
 * Provides methods for retrieving command line arguments and options.
 */
trait HasArgumentsAndOptions
{
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
}
