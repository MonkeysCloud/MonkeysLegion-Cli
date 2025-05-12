<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use RuntimeException;

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

        if (! is_dir($cacheDir)) {
            $this->info("Cache directory does not exist: {$cacheDir}");
            return self::SUCCESS;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cacheDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        $deleted = 0;
        foreach ($files as $file) {
            /* @var \SplFileInfo $file */
            if ($file->isFile()) {
                if (! @unlink($file->getRealPath())) {
                    throw new RuntimeException("Failed to delete cache file: {$file}");
                }
                $deleted++;
            }
        }

        $this->info("Cleared {$deleted} cached view files.");
        return self::SUCCESS;
    }
}