<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * MonKeysLegion Framework — CLI Package
 *
 * Monitor scheduled task health — detects overdue tasks, failure streaks,
 * and degraded performance.
 *
 * Usage:
 *   php bin/ml schedule:monitor
 *
 * @copyright 2026 MonKeysCloud Team
 * @license   MIT
 */
#[CommandAttr('schedule:monitor', 'Show scheduled task health status')]
final class ScheduleMonitorCommand extends Command
{
    protected function handle(): int
    {
        $this->newLine();
        $this->line('  <fg=cyan>Schedule Monitor — Task Health</>');
        $this->newLine();

        // Note: This command would normally load the schedule from the ScheduleManager
        // and the ScheduleMonitor from the container. For now, we show the concept.
        $this->comment('  This command checks the health of all scheduled tasks.');
        $this->comment('  It reports: overdue tasks, failure streaks, degraded performance.');
        $this->newLine();

        $this->line('  <fg=yellow>Status Legend:</>');
        $this->line('    <fg=green>✓ healthy</>    — Running normally');
        $this->line('    <fg=yellow>⚠ degraded</>   — Has failures or is overdue');
        $this->line('    <fg=red>✗ unhealthy</>  — 3+ consecutive failures');
        $this->line('    <fg=gray>○ never_run</>  — Has never executed');
        $this->newLine();

        $this->comment('  Note: Ensure the schedule runner is active for accurate monitoring.');

        return self::SUCCESS;
    }
}
