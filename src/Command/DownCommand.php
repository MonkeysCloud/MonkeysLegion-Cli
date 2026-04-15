<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * Put application in maintenance mode.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('down', 'Put the application in maintenance mode')]
final class DownCommand extends Command
{
    protected function handle(): int
    {
        $storageDir = function_exists('base_path')
            ? base_path('storage/framework')
            : 'storage/framework';

        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0o755, true);
        }

        $downFile = $storageDir . '/down';

        if (is_file($downFile)) {
            $this->warn('Application is already in maintenance mode.');

            return self::SUCCESS;
        }

        $secret = $this->option('secret');
        $retry  = $this->option('retry', '60');

        $payload = [
            'time'    => time(),
            'retry'   => is_numeric($retry) ? (int) $retry : 60,
            'secret'  => is_string($secret) ? $secret : null,
            'message' => is_string($this->option('message')) ? $this->option('message') : 'Service Unavailable',
        ];

        file_put_contents($downFile, json_encode($payload, JSON_PRETTY_PRINT));

        $this->info('🔒 Application is now in maintenance mode.');

        if (is_string($secret)) {
            $this->comment("  Bypass secret: {$secret}");
            $this->comment("  Access via: your-app.com/{$secret}");
        }

        return self::SUCCESS;
    }
}
