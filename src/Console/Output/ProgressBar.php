<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Console\Output;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Terminal progress bar with percentage, ETA, and items/sec display.
 *
 * Format: [████████░░░░░░░░] 50% 5/10 ETA 0:03 (1.7/s)
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class ProgressBar
{
    private int $current = 0;
    private float $startTime;
    private int $barWidth;
    private bool $started = false;

    /**
     * @param int    $total    Total items to process
     * @param int    $barWidth Character width of the bar
     * @param string $label    Optional label prefix
     */
    public function __construct(
        private readonly int $total,
        int $barWidth = 30,
        private readonly string $label = '',
    ) {
        $this->barWidth  = max(10, $barWidth);
        $this->startTime = microtime(true);
    }

    /**
     * Start the progress bar.
     */
    public function start(): void
    {
        $this->started   = true;
        $this->startTime = microtime(true);
        $this->current   = 0;
        $this->draw();
    }

    /**
     * Advance by N steps.
     */
    public function advance(int $steps = 1): void
    {
        if (!$this->started) {
            $this->start();
        }

        $this->current = min($this->current + $steps, $this->total);
        $this->draw();
    }

    /**
     * Set an exact position.
     */
    public function setProgress(int $current): void
    {
        $this->current = min(max(0, $current), $this->total);
        $this->draw();
    }

    /**
     * Complete the bar.
     */
    public function finish(): void
    {
        $this->current = $this->total;
        $this->draw();
        fwrite(STDERR, PHP_EOL);
    }

    // ── Internal ──────────────────────────────────────────────────

    private function draw(): void
    {
        $percent  = $this->total > 0 ? ($this->current / $this->total) : 1.0;
        $filled   = (int) round($percent * $this->barWidth);
        $empty    = $this->barWidth - $filled;

        $elapsed  = microtime(true) - $this->startTime;
        $rate     = $elapsed > 0 ? $this->current / $elapsed : 0.0;
        $eta      = ($rate > 0 && $this->current < $this->total)
            ? ($this->total - $this->current) / $rate
            : 0.0;

        $bar = "\033[32m" . str_repeat('█', $filled) . "\033[0m"
             . "\033[90m" . str_repeat('░', $empty) . "\033[0m";

        $statusParts = [
            sprintf("\033[1;37m%3d%%\033[0m", (int) ($percent * 100)),
            sprintf("\033[36m%d/%d\033[0m", $this->current, $this->total),
        ];

        if ($this->current < $this->total && $eta > 0) {
            $statusParts[] = sprintf("\033[33mETA %s\033[0m", $this->formatTime($eta));
        }

        if ($rate > 0) {
            $statusParts[] = sprintf("\033[90m(%.1f/s)\033[0m", $rate);
        }

        $prefix = $this->label !== '' ? "\033[37m{$this->label} \033[0m" : '';
        $line   = sprintf("\r%s[%s] %s", $prefix, $bar, implode(' ', $statusParts));

        fwrite(STDERR, $line);
    }

    /**
     * Format seconds into MM:SS or H:MM:SS.
     */
    private function formatTime(float $seconds): string
    {
        $s = (int) ceil($seconds);

        if ($s >= 3600) {
            return sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
        }

        return sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }
}
