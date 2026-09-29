<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * MonKeysLegion Framework — CLI Package
 *
 * Tail application log files in real-time with level filtering and
 * color-coded output.
 *
 * Usage:
 *   php bin/ml log:tail                    # Follow all logs
 *   php bin/ml log:tail --level=error      # Only errors
 *   php bin/ml log:tail --lines=50         # Show last 50 lines first
 *   php bin/ml log:tail --no-follow        # Print and exit (no follow)
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('log:tail', 'Tail application log files in real-time')]
final class LogTailCommand extends Command
{
    private const array LEVEL_COLORS = [
        'error'   => 31, // red
        'warning' => 33, // yellow
        'info'    => 36, // cyan
        'debug'   => 90, // bright black
        'notice'  => 35, // magenta
        'critical' => 31,
        'alert'   => 31,
        'emergency' => 31,
    ];

    protected function handle(): int
    {
        $logDir   = function_exists('base_path') ? base_path('storage/logs') : 'storage/logs';
        $level    = strtolower((string) ($this->option('level', 'all')));
        $lines    = (int) ($this->option('lines', 20));
        $follow   = !$this->hasOption('no-follow');

        // Find log files
        $logFiles = $this->findLogFiles($logDir);

        if ($logFiles === []) {
            $this->warn("No log files found in {$logDir}");
            return self::SUCCESS;
        }

        // If single file, use it; otherwise use the most recently modified
        $logFile = $logFiles[0];
        if (count($logFiles) > 1) {
            $this->comment("Multiple log files found, using: " . basename($logFile));
        }

        // Print initial tail
        $this->printInitialTail($logFile, $lines, $level);

        if (!$follow) {
            return self::SUCCESS;
        }

        // Follow mode
        $this->comment("Following {$logFile} (Ctrl+C to stop)...");
        $this->followFile($logFile, $level);

        return self::SUCCESS;
    }

    /**
     * Find all .log files in the log directory, sorted by modification time (newest first).
     *
     * @return list<string>
     */
    private function findLogFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.log') ?: [];
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));

        return $files;
    }

    /**
     * Print the last N lines from the file, filtered by level.
     */
    private function printInitialTail(string $file, int $lines, string $level): void
    {
        if (!is_file($file)) {
            return;
        }

        $content = file($file);
        if ($content === false) {
            return;
        }

        $tail = array_slice($content, max(0, count($content) - $lines));

        foreach ($tail as $line) {
            $this->printLogLine(rtrim($line), $level);
        }
    }

    /**
     * Follow a file for new lines (polling-based for cross-platform support).
     */
    private function followFile(string $file, string $level): void
    {
        $lastSize = file_exists($file) ? filesize($file) : 0;
        if (!is_int($lastSize)) {
            $lastSize = 0;
        }

        while (true) {
            usleep(1_000_000); // 1 second

            clearstatcache(true, $file);

            if (!file_exists($file)) {
                continue;
            }

            $currentSize = filesize($file);
            if (!is_int($currentSize)) {
                continue;
            }

            // File was truncated/rotated
            if ($currentSize < $lastSize) {
                $lastSize = 0;
            }

            if ($currentSize > $lastSize) {
                $handle = fopen($file, 'rb');
                if ($handle === false) {
                    continue;
                }

                fseek($handle, $lastSize);
                while (($line = fgets($handle)) !== false) {
                    $this->printLogLine(rtrim($line), $level);
                }
                fclose($handle);

                $lastSize = $currentSize;
            }
        }
    }

    /**
     * Print a single log line with optional level filtering and coloring.
     */
    private function printLogLine(string $line, string $filterLevel): void
    {
        if ($line === '') {
            return;
        }

        $detectedLevel = $this->detectLevel($line);

        // Apply level filter
        if ($filterLevel !== 'all' && $detectedLevel !== $filterLevel) {
            return;
        }

        // Try to parse as JSON (structured logging)
        $json = json_decode($line, true);
        if (is_array($json) && isset($json['level'], $json['message'])) {
            $this->printStructuredLog($json);
            return;
        }

        // Plain text — color by detected level
        $color = self::LEVEL_COLORS[$detectedLevel] ?? 0;
        if ($color > 0) {
            echo "\033[{$color}m{$line}\033[0m\n";
        } else {
            echo $line . "\n";
        }
    }

    /**
     * Pretty-print a structured JSON log entry.
     *
     * @param array<string, mixed> $entry
     */
    private function printStructuredLog(array $entry): void
    {
        $level    = strtolower((string) ($entry['level'] ?? 'info'));
        $message  = $entry['message'] ?? '';
        $ts       = $entry['timestamp'] ?? $entry['datetime'] ?? '';
        $context  = $entry['context'] ?? [];

        $color    = self::LEVEL_COLORS[$level] ?? 0;
        $levelStr = strtoupper($level);

        $prefix = '';
        if ($ts !== '') {
            $prefix = "\033[90m{$ts}\033[0m ";
        }

        $levelOutput = $color > 0
            ? "\033[{$color}m{$levelStr}\033[0m"
            : $levelStr;

        echo "{$prefix}{$levelOutput}: {$message}\n";

        if (!empty($context)) {
            echo "  \033[90m" . json_encode($context, JSON_UNESCAPED_SLASHES) . "\033[0m\n";
        }
    }

    /**
     * Detect the log level from a plain-text log line.
     */
    private function detectLevel(string $line): string
    {
        $line = strtolower($line);

        // Common patterns: "level.X" or "[LEVEL]" or "level: X"
        if (preg_match('/\b(error|critical|alert|emergency)\b/', $line)) {
            return 'error';
        }
        if (preg_match('/\b(warning|warn)\b/', $line)) {
            return 'warning';
        }
        if (preg_match('/\b(info)\b/', $line)) {
            return 'info';
        }
        if (preg_match('/\b(debug)\b/', $line)) {
            return 'debug';
        }
        if (preg_match('/\b(notice)\b/', $line)) {
            return 'notice';
        }

        return 'info';
    }
}
