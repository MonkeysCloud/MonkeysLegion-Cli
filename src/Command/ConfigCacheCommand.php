<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * Compile config files into a single cached array.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('config:cache', 'Compile config files into a cached file')]
final class ConfigCacheCommand extends Command
{
    protected function handle(): int
    {
        $configDir = function_exists('base_path') ? base_path('config') : 'config';
        $cacheDir  = function_exists('base_path') ? base_path('storage/cache') : 'storage/cache';

        if (!is_dir($configDir)) {
            $this->error("Config directory not found: {$configDir}");

            return self::FAILURE;
        }

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0o755, true);
        }

        $config = [];

        foreach (glob($configDir . '/*.php') ?: [] as $file) {
            $key = basename($file, '.php');

            try {
                $config[$key] = require $file;
            } catch (\Throwable $e) {
                $this->warn("Skipped {$key}: {$e->getMessage()}");
            }
        }

        $cacheFile = $cacheDir . '/config.php';
        $exported  = var_export($config, true);

        file_put_contents($cacheFile, "<?php\nreturn {$exported};\n");

        $this->info('✅ Config cached successfully.');
        $this->comment("  Path: {$cacheFile}");
        $this->comment('  Files: ' . count($config));

        return self::SUCCESS;
    }
}
