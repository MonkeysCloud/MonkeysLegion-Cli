<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Support;

use Composer\Autoload\ClassLoader;
use MonkeysLegion\Cli\Console\Command;

/**
 * Utility that finds every class extending Command and/or carrying #[Command].
 *
 * Scans all PSR-4 autoloaded namespaces for Cli/Command subdirectories,
 * excluding the CLI package's own namespace (already discovered by CliKernel).
 */
final class CommandFinder
{
    /**
     * @return iterable<class-string<Command>>
     */
    public static function all(): iterable
    {
        /** @var ClassLoader $loader */
        $loader = require base_path('vendor/autoload.php');

        /** @var array<string,string[]> $map prefix => [dir,...] */
        $map = $loader->getPrefixesPsr4();

        foreach ($map as $ns => $dirs) {
            // Skip CLI's own namespace — already discovered by CliKernel
            if ($ns === 'MonkeysLegion\\Cli\\') {
                continue;
            }

            // Skip the app namespace — discovered separately by CliKernel
            if ($ns === 'App\\') {
                continue;
            }

            foreach ($dirs as $dir) {
                $cmdPath = $dir . '/Cli/Command';
                if (!is_dir($cmdPath)) {
                    continue;
                }

                yield from self::scanDirectory($cmdPath, $dir, $ns);
            }
        }
    }

    /**
     * @return iterable<class-string<Command>>
     */
    private static function scanDirectory(string $cmdPath, string $dir, string $ns): iterable
    {
        /** @var \SplFileInfo $file */
        foreach (
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($cmdPath, \FilesystemIterator::SKIP_DOTS)
            ) as $file
        ) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $class = $ns
                . str_replace(
                    '/',
                    '\\',
                    substr(
                        $file->getPathname(),
                        strlen($dir) + 1,
                        -4
                    )
                );

            // Use class_exists with autoload to safely load the class
            // This avoids fatal errors from require_once for broken files
            try {
                if (
                    class_exists($class, true)
                    && is_subclass_of($class, Command::class, true)
                ) {
                    yield $class;
                }
            } catch (\Throwable) {
                // Skip classes that fail to load (e.g. visibility conflicts)
                continue;
            }
        }
    }
}
