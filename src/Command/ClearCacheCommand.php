<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Clear compiled view cache.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('cache:clear', 'Clear the compiled view cache')]
final class ClearCacheCommand extends Command
{
    protected function handle(): int
    {
        $cacheDir = function_exists('base_path') ? base_path('var/cache/views') : 'var/cache/views';

        if (!is_dir($cacheDir)) {
            if (!mkdir($cacheDir, 0o755, true) && !is_dir($cacheDir)) {
                $this->error("Unable to create cache directory: {$cacheDir}");

                return self::FAILURE;
            }

            $this->info("Cache directory created: {$cacheDir}");

            return self::SUCCESS;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cacheDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        $deleted = 0;

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->isFile()) {
                $realPath = $file->getRealPath();

                if (is_string($realPath) && @unlink($realPath)) {
                    $deleted++;
                } else {
                    $this->error("Failed to delete: {$realPath}");

                    return self::FAILURE;
                }
            }
        }

        $this->info("✅ Cleared {$deleted} cached file(s).");

        return self::SUCCESS;
    }
}
