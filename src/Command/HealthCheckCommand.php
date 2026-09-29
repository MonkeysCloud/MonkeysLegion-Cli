<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * MonKeysLegion Framework — CLI Package
 *
 * Run health checks from the command line.
 *
 * Usage:
 *   php bin/ml health:check
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('health:check', 'Run all registered health checks')]
final class HealthCheckCommand extends Command
{
    protected function handle(): int
    {
        $container = \MonkeysLegion\DI\Container::instance();

        $service = $container->get(\MonkeysLegion\Core\Health\HealthCheckService::class);

        if ($service === null) {
            $this->error('HealthCheckService not available in the container.');
            return self::FAILURE;
        }

        $result = $service->check();

        $this->newLine();
        $this->line('  <fg=cyan>Health Check Results</>');
        $this->newLine();

        foreach ($result['checks'] as $check) {
            $status = $check['status'];
            $color = match ($status) {
                'healthy'   => 'green',
                'degraded'  => 'yellow',
                'unhealthy' => 'red',
                default     => 'gray',
            };

            $name = str_pad($check['name'], 20);
            $statusStr = str_pad(strtoupper($status), 12);
            $latency = number_format($check['latency_ms'], 1) . 'ms';

            $this->line("  <fg={$color}>{$name} {$statusStr} {$latency}</>");
            if (!empty($check['message'])) {
                $this->line("    <fg=gray>{$check['message']}</>");
            }
        }

        $this->newLine();
        $overall = $result['status'];
        $totalLatency = number_format($result['latency_ms'], 1);

        if ($overall === 'healthy') {
            $this->info("✅ All checks passed ({$totalLatency}ms total).");
            return self::SUCCESS;
        }

        if ($overall === 'degraded') {
            $this->warn("⚠️  System degraded ({$totalLatency}ms total).");
            return self::SUCCESS; // degraded is not a failure
        }

        $this->error("❌ System unhealthy ({$totalLatency}ms total).");
        return self::FAILURE;
    }
}
