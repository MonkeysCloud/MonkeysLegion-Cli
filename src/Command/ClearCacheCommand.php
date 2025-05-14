<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use RuntimeException;
use SplFileInfo;

#[CommandAttr('cache:clear', 'Clear the compiled view cache (var/cache/views)')]
final class ClearCacheCommand extends Command
{
    public function __construct()
    {
        parent::__construct();
    }

    protected function handle(): int
    {
        $cacheDir = base_path('var/cache/views');

        // Ensure cache directory exists
        if (! is_dir($cacheDir)) {
            // Attempt to create it (0755, recursive)
            if (! @mkdir($cacheDir, 0755, true) && ! is_dir($cacheDir)) {
                $this->error("Unable to create cache directory: {$cacheDir}");
                return self::FAILURE;
            }
            $this->info("Cache directory created: {$cacheDir}");
            return self::SUCCESS;
        }

        // Iterate and delete all files
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cacheDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        $deleted = 0;
        foreach ($files as $file) {
            /* @var SplFileInfo $file */
            if ($file->isFile()) {
                if (! @unlink($file->getRealPath())) {
                    $this->error("Failed to delete cache file: {$file}");
                    return self::FAILURE;
                }
                $deleted++;
            }
        }

        $this->info("Cleared {$deleted} cached view file(s).");
        return self::SUCCESS;
    }
}