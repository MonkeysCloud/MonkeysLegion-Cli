<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * Compare .env with .env.example and report missing keys.
 * Unique to MonkeysLegion — neither Laravel nor Symfony has this.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('env:sync', 'Compare .env with .env.example for missing keys')]
final class EnvSyncCommand extends Command
{
    protected function handle(): int
    {
        $basePath = function_exists('base_path') ? base_path() : getcwd();
        $envFile  = $basePath . '/.env';
        $example  = $basePath . '/.env.example';

        if (!is_file($example)) {
            $this->error('.env.example not found.');

            return self::FAILURE;
        }

        $exampleKeys = $this->parseEnvKeys($example);
        $envKeys     = is_file($envFile) ? $this->parseEnvKeys($envFile) : [];

        $missing = array_diff($exampleKeys, $envKeys);
        $extra   = array_diff($envKeys, $exampleKeys);

        if ($missing === [] && $extra === []) {
            $this->info('✅ .env is in sync with .env.example — no differences.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($missing as $key) {
            $rows[] = ["\033[31m✗\033[0m", $key, 'Missing from .env'];
        }

        foreach ($extra as $key) {
            $rows[] = ["\033[33m⚠\033[0m", $key, 'Extra (not in .env.example)'];
        }

        $this->table(['', 'Key', 'Status'], $rows);

        if ($missing !== []) {
            $this->warn(count($missing) . ' key(s) missing from .env');
        }

        if ($extra !== []) {
            $this->comment(count($extra) . ' extra key(s) not in .env.example');
        }

        return $missing !== [] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Parse variable names from an env file.
     *
     * @return list<string>
     */
    private function parseEnvKeys(string $file): array
    {
        $keys  = [];
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            // Extract key
            if (preg_match('/^([A-Z_][A-Z0-9_]*)=/', $line, $m)) {
                $keys[] = $m[1];
            }
        }

        return $keys;
    }
}
