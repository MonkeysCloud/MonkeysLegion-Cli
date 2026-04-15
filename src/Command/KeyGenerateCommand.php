<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Generate a new APP_KEY in the .env file.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('key:generate', 'Generate a new APP_KEY in your .env file')]
final class KeyGenerateCommand extends Command
{
    protected function handle(): int
    {
        $envFile = function_exists('base_path') ? base_path('.env') : '.env';

        if (!is_file($envFile)) {
            $this->error(".env file not found at: {$envFile}");

            return self::FAILURE;
        }

        $key     = 'base64:' . base64_encode(random_bytes(32));
        $content = file_get_contents($envFile);

        if (!is_string($content)) {
            $this->error('Could not read .env file.');

            return self::FAILURE;
        }

        if (preg_match('/^APP_KEY=.*/m', $content)) {
            $content = preg_replace('/^APP_KEY=.*/m', "APP_KEY={$key}", $content);
        } else {
            $content .= "\nAPP_KEY={$key}\n";
        }

        file_put_contents($envFile, $content);

        $this->info("✅ APP_KEY set: {$key}");

        return self::SUCCESS;
    }
}