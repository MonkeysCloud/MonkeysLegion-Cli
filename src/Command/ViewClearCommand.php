<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * MonKeysLegion Framework — CLI Package
 *
 * Clear the compiled template (view) cache.
 *
 * Usage:
 *   php bin/ml view:clear
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('view:clear', 'Clear the compiled template cache')]
final class ViewClearCommand extends Command
{
    protected function handle(): int
    {
        $cacheDir = function_exists('base_path') ? base_path('var/cache/views') : 'var/cache/views';

        if (!is_dir($cacheDir)) {
            $this->info('✅ View cache is already empty.');
            return self::SUCCESS;
        }

        $deleted = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cacheDir, \FilesystemIterator::SKIP_DOTS),
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

        $this->info("✅ Cleared {$deleted} compiled template file(s).");

        return self::SUCCESS;
    }
}
