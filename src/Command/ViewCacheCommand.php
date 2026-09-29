<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * MonKeysLegion Framework — CLI Package
 *
 * Pre-compile all .ml.php templates into the view cache.
 *
 * Usage:
 *   php bin/ml view:cache
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('view:cache', 'Pre-compile all templates into the view cache')]
final class ViewCacheCommand extends Command
{
    protected function handle(): int
    {
        $viewsDir = function_exists('base_path') ? base_path('resources/views') : 'resources/views';

        if (!is_dir($viewsDir)) {
            $this->error("Views directory not found: {$viewsDir}");
            return self::FAILURE;
        }

        $templates = $this->findTemplates($viewsDir);

        if ($templates === []) {
            $this->warn('No templates found.');
            return self::SUCCESS;
        }

        $compiled = 0;
        $failed   = 0;

        foreach ($templates as $dotName => $fullPath) {
            try {
                // Force compilation by rendering with empty data.
                // The renderer compiles the template and caches it.
                if (function_exists('app')) {
                    $container = app();
                    if ($container !== null && $container->has(\MonkeysLegion\Template\Renderer::class)) {
                        $renderer = $container->get(\MonkeysLegion\Template\Renderer::class);
                        $renderer->render($dotName, []);
                    }
                }
                $compiled++;
            } catch (\Throwable $e) {
                $this->error("Failed to compile {$dotName}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("✅ Compiled {$compiled} template(s).");
        if ($failed > 0) {
            $this->warn("⚠ {$failed} template(s) failed to compile.");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Find all .ml.php templates and map dot-names to file paths.
     *
     * @return array<string, string> [dotName => filePath]
     */
    private function findTemplates(string $dir): array
    {
        $templates = [];
        $iterator  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $name = $file->getFilename();
            if (!str_ends_with($name, '.ml.php')) {
                continue;
            }

            // Convert path to dot notation
            $relative = substr($file->getPathname(), strlen($dir) + 1);
            $relative = preg_replace('/\.ml\.php$/', '', $relative) ?? $relative;
            $dotName  = str_replace(DIRECTORY_SEPARATOR, '.', $relative);

            $templates[$dotName] = $file->getPathname();
        }

        ksort($templates);
        return $templates;
    }
}
