<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command;
use MonkeysLegion\Cli\Console\Command as BaseCommand;
use RuntimeException;

#[Command('key:generate', 'Generate a new APP_KEY in your .env file')]
final class KeyGenerateCommand extends BaseCommand
{
    public function handle(): int
    {
        $envFile    = base_path('.env');
        $exampleEnv = base_path('.env.example');

        // Ensure we have a base .env
        if (! is_file($envFile)) {
            if (! is_file($exampleEnv)) {
                $this->error(".env.example not found; cannot generate .env");
                return self::FAILURE;
            }
            copy($exampleEnv, $envFile);
            $this->info("Created .env from .env.example");
        }

        // Read and replace or append APP_KEY
        $contents = file_get_contents($envFile);
        if ($contents === false) {
            throw new RuntimeException("Unable to read {$envFile}");
        }

        // Generate a 32-byte random key, base64‐encoded
        $key = rtrim(base64_encode(random_bytes(32)), '=');
        $line = "APP_KEY={$key}";

        if (preg_match('/^APP_KEY=.*$/m', $contents)) {
            $contents = preg_replace(
                '/^APP_KEY=.*$/m',
                $line,
                $contents
            );
        } else {
            $contents = trim($contents) . "\n\n" . $line . "\n";
        }

        file_put_contents($envFile, $contents);
        $this->info("Set APP_KEY in .env");

        return self::SUCCESS;
    }
}