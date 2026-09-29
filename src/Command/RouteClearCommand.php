<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Router\RouteCache;

/**
 * MonKeysLegion Framework — CLI Package
 *
 * Clear the compiled route cache.
 *
 * Usage:
 *   php bin/ml route:clear
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('route:clear', 'Clear the compiled route cache')]
final class RouteClearCommand extends Command
{
    public function __construct(
        private readonly RouteCache $cache,
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        if ($this->cache->clear()) {
            $this->info('✅ Route cache cleared.');
            return self::SUCCESS;
        }

        $this->warn('Route cache was already empty or could not be cleared.');
        return self::SUCCESS;
    }
}
