<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Router\RouteCollection;

/**
 * Lists every registered route in a simple console table.
 */
final class RouteListCommand extends Command
{
    protected string $signature   = 'route:list';
    protected string $description = 'Display all registered routes.';

    public function __construct(private RouteCollection $routes)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $rows = [];

        foreach ($this->routes as $route) {
            $handler = implode('::', $route->getHandler());

            foreach ($route->getMethods() as $verb) {
                $rows[] = sprintf(
                    "%-20s  %-6s  %-30s  %s",
                    $route->getName() ?: '—',
                    $verb,
                    $route->getPath(),
                    $handler
                );
            }
        }

        // Header
        $this->line(sprintf(
            "%-20s  %-6s  %-30s  %s",
            'Name', 'Verb', 'Path', 'Handler'
        ));
        $this->line(str_repeat('-', 80));

        // Body
        foreach ($rows as $row) {
            $this->line($row);
        }

        return 0;
    }
}