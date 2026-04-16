<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * Bring application out of maintenance mode.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('up', 'Bring the application out of maintenance mode')]
final class UpCommand extends Command
{
    protected function handle(): int
    {
        $storageDir = function_exists('base_path')
            ? base_path('storage/framework')
            : 'storage/framework';

        $downFile = $storageDir . '/down';

        if (!is_file($downFile)) {
            $this->info('Application is already live.');

            return self::SUCCESS;
        }

        unlink($downFile);

        $this->info('🟢 Application is now live.');

        return self::SUCCESS;
    }
}
