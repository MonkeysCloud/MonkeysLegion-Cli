<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * MonKeysLegion Framework — CLI Package
 *
 * Remove the compiled DI container file.
 *
 * Usage:
 *   php bin/ml container:clear
 *
 * @copyright 2026 MonKeysCloud Team
 * @license   MIT
 */
#[CommandAttr('container:clear', 'Remove the compiled DI container')]
final class ContainerClearCommand extends Command
{
    protected function handle(): int
    {
        $basePath = function_exists('base_path') ? base_path() : getcwd();
        $outputFile = $basePath . '/storage/framework/container/compiled_container.php';

        if (!is_file($outputFile)) {
            $this->comment('No compiled container found — nothing to clear.');
            return self::SUCCESS;
        }

        if (@unlink($outputFile)) {
            $this->info('✅ Compiled container removed.');

            // Invalidate OPcache for this file
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($outputFile, true);
            }

            return self::SUCCESS;
        }

        $this->error('❌ Could not remove compiled container file.');
        return self::FAILURE;
    }
}
