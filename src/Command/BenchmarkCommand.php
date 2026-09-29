<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * MonKeysLegion Framework — CLI Package
 *
 * Run the PHPBench benchmark suite.
 *
 * Usage:
 *   php bin/ml benchmark
 *   php bin/ml benchmark --benchmark=RoutingBench
 *   php bin/ml benchmark --report=aggregate
 *   php bin/ml benchmark --iterations=20
 *
 * @copyright 2026 MonKeysCloud Team
 * @license   MIT
 */
#[CommandAttr('benchmark', 'Run the PHPBench benchmark suite')]
final class BenchmarkCommand extends Command
{
    protected function handle(): int
    {
        $basePath = function_exists('base_path') ? base_path() : getcwd();
        $phpbench = $basePath . '/vendor/bin/phpbench';

        // Check if PHPBench is installed
        if (!is_file($phpbench)) {
            $this->error('❌ PHPBench is not installed.');
            $this->newLine();
            $this->comment('Install with: composer require --dev phpbench/phpbench');
            return self::FAILURE;
        }

        $benchmark = (string) ($this->option('benchmark', ''));
        $iterations = (string) ($this->option('iterations', ''));
        $report = (string) ($this->option('report', 'default'));
        $revs = (string) ($this->option('revs', ''));

        // Build command
        $cmd = $phpbench . ' run';

        if ($benchmark !== '') {
            $cmd .= ' tests/Performance/Benchmarks/' . escapeshellarg($benchmark . '.php');
        } else {
            $cmd .= ' tests/Performance/Benchmarks';
        }

        $cmd .= ' --report=' . escapeshellarg($report);

        if ($iterations !== '') {
            $cmd .= ' --iterations=' . escapeshellarg($iterations);
        }
        if ($revs !== '') {
            $cmd .= ' --revs=' . escapeshellarg($revs);
        }

        $this->info('🚀 Running PHPBench benchmark suite…');
        $this->newLine();

        // Execute PHPBench
        @passthru('php ' . $cmd, $result);

        return $result === 0 ? self::SUCCESS : self::FAILURE;
    }
}
