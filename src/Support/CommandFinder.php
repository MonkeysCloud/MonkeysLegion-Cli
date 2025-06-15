<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Support;

final class CommandFinder
{
    /**
     * @return class-string[]
     */
    public static function all(string $baseDir, string $baseNs): array
    {
        $len = \strlen($baseDir) + 1;
        $cmd = [];

        foreach (new \RecursiveIteratorIterator(
                     new \RecursiveDirectoryIterator($baseDir)
                 ) as $file) {

            if (!$file->isFile() || $file->getExtension() !== 'php') continue;

            $fqcn = $baseNs . '\\' .
                    \str_replace('/', '\\',
                        \substr($file->getPathname(), $len, -4)   // trim base & .php
                    );

            // is_a() with autoload & allow-string = true
            if (\is_subclass_of($fqcn, \MonkeysLegion\Cli\Console\Command::class, true)) {
                $cmd[] = $fqcn;
            }
        }

        return $cmd;
    }
}