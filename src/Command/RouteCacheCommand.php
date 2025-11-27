<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Router\RouteCache;
use MonkeysLegion\Router\RouteCollection;
use MonkeysLegion\Core\Routing\RouteLoader;

/**
 * CLI command for managing route cache.
 * 
 * Usage:
 *   php ml route:cache          # Cache all routes
 *   php ml route:cache clear    # Clear the route cache
 *   php ml route:cache status   # Show cache status
 */
#[CommandAttr('route:cache', 'Cache all application routes for production')]
final class RouteCacheCommand extends Command
{
    public function __construct(
        private RouteCache $cache,
        private RouteCollection $routes,
        private RouteLoader $loader
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $argv = $_SERVER['argv'] ?? [];
        $action = (is_array($argv) && isset($argv[2]) && is_string($argv[2]))
            ? $argv[2]
            : 'cache';

        return match ($action) {
            'clear' => $this->clearCache(),
            'status' => $this->showStatus(),
            default => $this->cacheRoutes(),
        };
    }

    private function cacheRoutes(): int
    {
        $this->info('Caching routes...');

        // Ensure routes are loaded
        $this->loader->loadControllers();

        // Get all routes from collection
        $allRoutes = $this->routes->all();
        $namedRoutes = $this->routes->getNamedRoutes();

        // Save to cache
        if ($this->cache->save($allRoutes, $namedRoutes)) {
            $count = count($allRoutes);
            $named = count($namedRoutes);

            $this->info('✅  Routes cached successfully!');
            $this->line("   Total routes: {$count}");
            $this->line("   Named routes: {$named}");

            $stats = $this->cache->getStats();
            if ($stats['exists']) {
                $size = $this->formatBytes($stats['size']);
                $this->line("   Cache file: {$stats['path']}");
                $this->line("   Cache size: {$size}");
            }

            return self::SUCCESS;
        }

        $this->error('Failed to cache routes.');
        return self::FAILURE;
    }

    private function clearCache(): int
    {
        $this->info('Clearing route cache...');

        if ($this->cache->clear()) {
            $this->info('✅  Route cache cleared successfully!');
            return self::SUCCESS;
        }

        $this->error('Failed to clear route cache.');
        return self::FAILURE;
    }

    private function showStatus(): int
    {
        $this->info('Route Cache Status');
        $this->line(str_repeat('=', 40));

        $stats = $this->cache->getStats();

        if (!$stats['exists']) {
            $this->line('Cache: Not cached');
            $this->line('');
            $this->line("Run 'php ml route:cache' to cache routes.");
            return self::SUCCESS;
        }

        $this->info('Cache: Active');
        $this->line("Path:     {$stats['path']}");
        $this->line("Size:     " . $this->formatBytes($stats['size']));
        $this->line("Modified: " . date('Y-m-d H:i:s', $stats['modified']));

        // Load and display route stats
        $cached = $this->cache->load();
        if ($cached) {
            $totalRoutes = count($cached['routes'] ?? []);
            $namedRoutes = count($cached['namedRoutes'] ?? []);

            $this->line('');
            $this->info('Cached Routes:');
            $this->line("  Total: {$totalRoutes}");
            $this->line("  Named: {$namedRoutes}");

            // Group by method
            $byMethod = [];
            foreach ($cached['routes'] ?? [] as $route) {
                $method = $route['method'] ?? 'UNKNOWN';
                $byMethod[$method] = ($byMethod[$method] ?? 0) + 1;
            }

            if (!empty($byMethod)) {
                $this->line('');
                $this->info('By HTTP Method:');
                foreach ($byMethod as $method => $count) {
                    $this->line("  {$method}: {$count}");
                }
            }
        }

        return self::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        return sprintf("%.2f %s", $bytes / pow(1024, $power), $units[$power]);
    }
}
