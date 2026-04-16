<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * Display framework environment information.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('about', 'Display framework, PHP, and environment information')]
final class AboutCommand extends Command
{
    protected function handle(): int
    {
        $this->newLine();
        $this->cliLine()
            ->add('  MonkeysLegion Framework ', 'green', 'bold')
            ->add('v2.0', 'cyan')
            ->print();
        $this->newLine();

        // ── Environment ──────────────────────────────────────────
        $env   = getenv('APP_ENV') ?: 'production';
        $debug = getenv('APP_DEBUG') === 'true' ? 'ON' : 'OFF';

        $this->table(
            ['Setting', 'Value'],
            [
                ['PHP Version', PHP_VERSION],
                ['PHP SAPI', PHP_SAPI],
                ['OS', PHP_OS_FAMILY . ' (' . php_uname('m') . ')'],
                ['Environment', $env],
                ['Debug Mode', $debug],
                ['Timezone', date_default_timezone_get()],
                ['Memory Limit', ini_get('memory_limit') ?: 'unknown'],
                ['Max Execution', ini_get('max_execution_time') . 's'],
            ],
        );

        // ── Loaded extensions ────────────────────────────────────
        $importantExts = ['pdo', 'pdo_mysql', 'pdo_pgsql', 'pdo_sqlite', 'mbstring', 'openssl', 'curl', 'readline', 'sodium', 'pcov', 'xdebug'];
        $extRows       = [];

        foreach ($importantExts as $ext) {
            $loaded = extension_loaded($ext);
            $extRows[] = [
                $ext,
                $loaded ? "\033[32m✓ Loaded\033[0m" : "\033[90m✗ Not loaded\033[0m",
            ];
        }

        $this->newLine();
        $this->table(['Extension', 'Status'], $extRows);

        // ── Installed packages ───────────────────────────────────
        $lockFile = function_exists('base_path') ? base_path('composer.lock') : 'composer.lock';

        if (is_file($lockFile)) {
            $lock = json_decode(file_get_contents($lockFile) ?: '{}', true);
            $pkgs = is_array($lock['packages'] ?? null) ? $lock['packages'] : [];

            $mlPkgs = array_filter(
                $pkgs,
                static fn(array $pkg): bool => str_starts_with((string) ($pkg['name'] ?? ''), 'monkeyscloud/'),
            );

            if ($mlPkgs !== []) {
                $pkgRows = array_map(
                    static fn(array $pkg): array => [
                        (string) ($pkg['name'] ?? ''),
                        (string) ($pkg['version'] ?? 'dev'),
                    ],
                    array_values($mlPkgs),
                );

                $this->newLine();
                $this->table(['Package', 'Version'], $pkgRows);
            }
        }

        return self::SUCCESS;
    }
}
