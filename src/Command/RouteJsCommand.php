<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Router\RouteCollection;
use MonkeysLegion\Vite\RouteExporter;

/**
 * Export named routes to TypeScript for frontend use.
 *
 * Usage:
 *   php bin/ml route:js
 *   php bin/ml route:js --output=resources/js/types/routes.ts
 */
#[CommandAttr('route:js', 'Export named routes to TypeScript')]
final class RouteJsCommand extends Command
{
    protected function handle(): int
    {
        $container = \MonkeysLegion\DI\Container::instance();

        if ($container === null || !$container->has(RouteCollection::class)) {
            $this->error('RouteCollection not available in the container.');
            return self::FAILURE;
        }

        /** @var RouteCollection $routes */
        $routes = $container->get(RouteCollection::class);
        $exporter = new RouteExporter($routes);

        $basePath = function_exists('base_path') ? base_path() : getcwd();
        $output = (string) ($this->option('output', $basePath . '/resources/js/types/routes.ts'));

        $written = $exporter->writeToFile($output);

        $count = count(array_filter(
            $routes->all(),
            fn($r) => $r->name !== '',
        ));

        $this->info("✅ Exported {$count} named routes to: {$written}");

        return self::SUCCESS;
    }
}
