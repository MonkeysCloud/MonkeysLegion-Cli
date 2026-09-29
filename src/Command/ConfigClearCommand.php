<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * MonKeysLegion Framework — CLI Package
 *
 * Clear the cached configuration files.
 *
 * Usage:
 *   php bin/ml config:clear
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('config:clear', 'Clear the cached configuration files')]
final class ConfigClearCommand extends Command
{
    protected function handle(): int
    {
        $cacheDir = function_exists('base_path') ? base_path('var/cache/config') : 'var/cache/config';

        $deleted = $this->clearDirectory($cacheDir);

        if ($deleted === 0) {
            $this->warn('No cached configuration files found.');
        } else {
            $this->info("✅ Cleared {$deleted} cached config file(s).");
        }

        return self::SUCCESS;
    }

    private function clearDirectory(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $deleted = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $path = $file->getRealPath();
                if (is_string($path) && @unlink($path)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }
}
