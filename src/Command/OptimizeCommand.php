<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * Run all optimization tasks in one command.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('optimize', 'Run config:cache + route:cache + clear old caches')]
final class OptimizeCommand extends Command
{
    protected function handle(): int
    {
        $this->info('🚀 Optimizing application…');
        $this->newLine();

        $tasks = [
            'Caching config'  => fn() => $this->runSub('config:cache'),
            'Clearing cache'  => fn() => $this->runSub('cache:clear'),
            'Clearing stale'  => fn() => $this->clearStaleCache(),
        ];

        foreach ($tasks as $label => $task) {
            try {
                $task();
                $this->cliLine()
                    ->add('  ✓ ', 'green')
                    ->add($label, 'white')
                    ->print();
            } catch (\Throwable $e) {
                $this->cliLine()
                    ->add('  ✗ ', 'red')
                    ->add("{$label}: {$e->getMessage()}", 'white')
                    ->print();
            }
        }

        $this->newLine();
        $this->info('✅ Optimization complete.');

        return self::SUCCESS;
    }

    private function runSub(string $signature): void
    {
        match ($signature) {
            'config:cache' => (new ConfigCacheCommand())->__invoke(),
            'cache:clear'  => (new ClearCacheCommand())->__invoke(),
            default        => throw new \RuntimeException("Sub-command '{$signature}' not implemented."),
        };
    }

    private function clearStaleCache(): void
    {
        $cacheDir = function_exists('base_path') ? base_path('storage/cache') : 'storage/cache';

        if (!is_dir($cacheDir)) {
            return;
        }

        $count = 0;

        foreach (glob($cacheDir . '/*.tmp') ?: [] as $file) {
            unlink($file);
            $count++;
        }

        if ($count > 0) {
            $this->comment("  Cleared {$count} stale cache files");
        }
    }
}
