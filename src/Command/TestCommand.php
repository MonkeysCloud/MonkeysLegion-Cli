<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * MonKeysLegion Framework — CLI Package
 *
 * Run PHPUnit tests with pass-through arguments.
 *
 * Usage:
 *   php bin/ml test                          # Run all tests
 *   php bin/ml test --filter=UserTest        # Run specific test
 *   php bin/ml test --suite=Unit             # Run specific suite
 *   php bin/ml test --coverage               # With coverage
 *   php bin/ml test --parallel               # Run in parallel (via paratest)
 *   php bin/ml test Unit/AuthTest.php        # Run specific file
 *
 * All unknown options are forwarded to PHPUnit.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('test', 'Run PHPUnit tests with pass-through arguments')]
final class TestCommand extends Command
{
    protected function handle(): int
    {
        $phpunit = $this->findPhpunit();

        if ($phpunit === null) {
            $this->error('PHPUnit is not installed.');
            $this->comment('Install it with: composer require --dev phpunit/phpunit');
            return self::FAILURE;
        }

        // Build the command
        $command = $this->buildCommand($phpunit);

        if ($this->hasOption('parallel')) {
            return $this->runParallel($command);
        }

        $this->comment("Running: {$command}");

        // Passthrough to PHPUnit, inheriting stdout/stderr/stdin
        $exitCode = 0;
        passthru($command, $exitCode);

        return $exitCode === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Find the PHPUnit binary.
     */
    private function findPhpunit(): ?string
    {
        $candidates = [
            'vendor/bin/phpunit',
            'bin/phpunit',
            'phpunit',
        ];

        foreach ($candidates as $candidate) {
            $path = function_exists('base_path') ? base_path($candidate) : $candidate;
            if (is_file($path)) {
                return $path;
            }
        }

        // Check global
        $which = @shell_exec('which phpunit 2>/dev/null');
        if (is_string($which) && trim($which) !== '') {
            return trim($which);
        }

        return null;
    }

    /**
     * Build the PHPUnit command string.
     */
    private function buildCommand(string $phpunit): string
    {
        $parts = [escapeshellarg(PHP_BINARY), escapeshellarg($phpunit)];

        // Colors
        if ($this->supportsColor()) {
            $parts[] = '--colors=always';
        }

        // Coverage
        if ($this->hasOption('coverage')) {
            $driver = $this->option('coverage', 'text');
            if ($driver === 'html') {
                $parts[] = '--coverage-html var/coverage';
            } else {
                $parts[] = '--coverage-text';
            }
        }

        // Filter
        $filter = $this->option('filter');
        if (is_string($filter) && $filter !== '') {
            $parts[] = '--filter=' . escapeshellarg($filter);
        }

        // Suite
        $suite = $this->option('suite');
        if (is_string($suite) && $suite !== '') {
            $parts[] = '--testsuite=' . escapeshellarg($suite);
        }

        // Stop on failure
        if ($this->hasOption('stop-on-failure')) {
            $parts[] = '--stop-on-failure';
        }

        // Verbose
        if ($this->hasOption('verbose') || $this->hasOption('v')) {
            $parts[] = '--verbose';
        }

        // File/pattern arguments (positional)
        $file = $this->argument(0);
        if (is_string($file) && $file !== '') {
            $parts[] = escapeshellarg($file);
        }

        return implode(' ', $parts);
    }

    /**
     * Run tests in parallel using paratest (if available).
     */
    private function runParallel(string $baseCommand): int
    {
        $paratest = function_exists('base_path') ? base_path('vendor/bin/paratest') : 'vendor/bin/paratest';

        if (!is_file($paratest)) {
            $this->error('paratest is not installed. Install with: composer require --dev brianium/paratest');
            return self::FAILURE;
        }

        // Replace phpunit with paratest
        $command = str_replace(escapeshellarg($this->findPhpunit() ?? 'phpunit'), escapeshellarg($paratest), $baseCommand);

        $this->comment("Running in parallel: {$command}");

        $exitCode = 0;
        passthru($command, $exitCode);

        return $exitCode === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function supportsColor(): bool
    {
        return (bool) getenv('FORCE_COLOR') || (function_exists('posix_isatty') && posix_isatty(STDOUT));
    }
}
