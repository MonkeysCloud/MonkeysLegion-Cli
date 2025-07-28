<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Support;

use Composer\Autoload\ClassLoader;
use MonkeysLegion\Cli\Console\Command;

/**
 * Utility that finds every class extending Command and/or carrying #[Command].
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

        /** @var array<string,string[]> $map prefix ⇒ [dir,…] */
        $map = $loader->getPrefixesPsr4();

        foreach ($map as $ns => $dirs) {
            foreach ($dirs as $dir) {
                $cmdPath = $dir . '/Cli/Command';
                if (!is_dir($cmdPath)) {
                    continue;
                }
                /** @var \SplFileInfo $file */
                foreach (
                    new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($cmdPath)
                    ) as $file
                ) {
                    if (
                        $file->isFile()
                        && $file->getExtension() === 'php'
                    ) {
                        require_once $file->getPathname();

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

                        if (
                            is_subclass_of($class, Command::class, true)
                        ) {
                            yield $class;
                        }
                    }
                }
            }
        }
    }
}
