<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Router\Router;
use MonkeysLegion\Core\Routing\RouteLoader;

/**
 * CLI command for listing all registered routes.
 * 
 * Usage:
 *   php ml route:list                    # List all routes
 *   php ml route:list --method=GET       # Filter by method
 *   php ml route:list --path=/api        # Filter by path pattern
 */
#[CommandAttr('route:list', 'List all registered application routes')]
final class RouteListCommand extends Command
{
    public function __construct(
        private Router $router,
        private RouteLoader $loader
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        // Ensure routes are loaded
        $this->loader->loadControllers();

        $routes = $this->router->getRoutes()->all();

        // Parse filters from args
        $argv = $_SERVER['argv'] ?? [];
        $args = array_slice($argv, 2);
        $filters = $this->parseFilters($args);

        // Apply filters
        $routes = $this->filterRoutes($routes, $filters);

        if (empty($routes)) {
            $this->line('No routes found.');
            return self::SUCCESS;
        }

        // Calculate column widths
        $methodWidth = 9;
        $pathWidth = 50;

        // Print header
        $this->line('');
        $header = str_pad('Method', $methodWidth)
            . str_pad('Path', $pathWidth)
            . 'Handler';
        $this->info($header);
        $this->line(str_repeat('-', $methodWidth + $pathWidth + 40));

        // Print routes
        foreach ($routes as $route) {
            $method = $route['method'];
            $path = $this->truncate($route['path'], $pathWidth - 2);

            // Get handler info
            $handler = $route['handler'] ?? null;
            $handlerStr = '-';
            if (is_array($handler)) {
                $class = is_object($handler[0]) ? get_class($handler[0]) : (string)$handler[0];
                $handlerStr = $this->shortClassName($class) . '::' . ($handler[1] ?? '?');
            } elseif (is_string($handler)) {
                $handlerStr = $this->shortClassName($handler);
            }
            $handlerStr = $this->truncate($handlerStr, 40);

            // Color-code methods
            $methodColored = $this->colorMethod($method);

            $line = str_pad($methodColored, $methodWidth + $this->colorLength($method))
                . str_pad($path, $pathWidth)
                . $handlerStr;
            $this->line($line);
        }

        $this->line('');
        $this->info('Total: ' . count($routes) . ' routes');

        return self::SUCCESS;
    }

    /**
     * Parse filter arguments from command line.
     * 
     * @param array<int, string> $args
     * @return array{method: string|null, path: string|null}
     */
    private function parseFilters(array $args): array
    {
        $filters = [
            'method' => null,
            'path' => null,
        ];

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--method=')) {
                $filters['method'] = strtoupper(substr($arg, 9));
            } elseif (str_starts_with($arg, '--path=')) {
                $filters['path'] = substr($arg, 7);
            }
        }

        return $filters;
    }

    /**
     * Filter routes based on criteria.
     * 
     * @param array<int, array<string, mixed>> $routes
     * @param array{method: string|null, path: string|null} $filters
     * @return array<int, array<string, mixed>>
     */
    private function filterRoutes(array $routes, array $filters): array
    {
        return array_filter($routes, function ($route) use ($filters) {
            if ($filters['method'] && ($route['method'] ?? '') !== $filters['method']) {
                return false;
            }

            if ($filters['path'] && !str_contains($route['path'] ?? '', $filters['path'])) {
                return false;
            }

            return true;
        });
    }

    /**
     * Apply ANSI color codes to HTTP methods.
     */
    private function colorMethod(string $method): string
    {
        return match ($method) {
            'GET' => "\033[32m{$method}\033[0m",     // Green
            'POST' => "\033[33m{$method}\033[0m",   // Yellow
            'PUT' => "\033[34m{$method}\033[0m",    // Blue
            'PATCH' => "\033[36m{$method}\033[0m",  // Cyan
            'DELETE' => "\033[31m{$method}\033[0m", // Red
            'OPTIONS' => "\033[35m{$method}\033[0m", // Magenta
            default => $method,
        };
    }

    /**
     * Get the length of ANSI color codes (for padding calculations).
     */
    private function colorLength(string $method): int
    {
        // ANSI codes add ~9 characters that don't display
        return 9;
    }

    /**
     * Truncate text to a maximum length with ellipsis.
     */
    private function truncate(string $text, int $length): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }
        return substr($text, 0, $length - 3) . '...';
    }

    /**
     * Get the short class name (without namespace).
     */
    private function shortClassName(string $class): string
    {
        $parts = explode('\\', $class);
        return end($parts);
    }
}
