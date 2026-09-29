<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * MonKeysLegion Framework — CLI Package
 *
 * Display OPcache status, memory usage, hit rate, and recommendations.
 *
 * Usage:
 *   php bin/ml opcache:status
 *
 * @copyright 2026 MonKeysCloud Team
 * @license   MIT
 */
#[CommandAttr('opcache:status', 'Show OPcache status, memory usage, and recommendations')]
final class OpcacheStatusCommand extends Command
{
    protected function handle(): int
    {
        $this->newLine();
        $this->line('  <fg=cyan>OPcache Status</>');
        $this->newLine();

        if (!function_exists('opcache_get_status')) {
            $this->error('❌ OPcache extension is not installed or not enabled.');
            $this->newLine();
            $this->comment('Install with: pecl install opcache');
            $this->comment('Enable in php.ini: opcache.enable=1');
            return self::FAILURE;
        }

        $status = @opcache_get_status(false);
        if ($status === false) {
            $this->error('❌ OPcache is installed but disabled.');
            $this->comment('Enable in php.ini: opcache.enable=1');
            return self::FAILURE;
        }

        // ── General Status ───────────────────────────────────────
        $this->line('  <fg=green>✓ OPcache is enabled</>');
        $this->newLine();

        // ── Memory Usage ─────────────────────────────────────────
        $mem = $status['memory_usage'] ?? [];
        $used = $mem['used_memory'] ?? 0;
        $free = $mem['free_memory'] ?? 0;
        $total = $used + $free;
        $wasted = $mem['wasted_memory'] ?? 0;
        $usedPct = $total > 0 ? round(($used / $total) * 100, 1) : 0;
        $wastedPct = $total > 0 ? round(($wasted / $total) * 100, 1) : 0;

        $this->line('  <fg=yellow>Memory Usage</>');
        $this->line("    Used:        {$this->formatBytes($used)} ({$usedPct}%)");
        $this->line("    Free:        {$this->formatBytes($free)}");
        $this->line("    Wasted:      {$this->formatBytes($wasted)} ({$wastedPct}%)");
        $this->line("    Total:       {$this->formatBytes($total)}");
        $this->newLine();

        // ── Hit Rate ─────────────────────────────────────────────
        $hits = $status['opcache_statistics']['hits'] ?? 0;
        $misses = $status['opcache_statistics']['misses'] ?? 0;
        $total2 = $hits + $misses;
        $hitRate = $total2 > 0 ? round(($hits / $total2) * 100, 2) : 0;

        $this->line('  <fg=yellow>Statistics</>');
        $this->line("    Hits:        {$hits}");
        $this->line("    Misses:      {$misses}");
        $this->line("    Hit Rate:    {$hitRate}%");
        $this->line("    Cached:      " . ($status['opcache_statistics']['num_cached_scripts'] ?? 0) . " scripts");
        $this->line("    Keys:        " . ($status['opcache_statistics']['num_cached_keys'] ?? 0) . " keys");
        $this->line("    Restarts:    " . ($status['opcache_statistics']['opcache_restarts'] ?? 0) . " restarts");
        $this->newLine();

        // ── Interned Strings ─────────────────────────────────────
        $interned = $status['interned_strings_usage'] ?? [];
        $this->line('  <fg=yellow>Interned Strings</>');
        $this->line("    Used:        {$this->formatBytes($interned['used_memory'] ?? 0)}");
        $this->line("    Free:        {$this->formatBytes($interned['free_memory'] ?? 0)}");
        $this->line("    Buffer:      {$this->formatBytes($interned['buffer_size'] ?? 0)}");
        $this->line("    Count:       " . ($interned['number_of_strings'] ?? 0) . " strings");
        $this->newLine();

        // ── JIT Status ───────────────────────────────────────────
        $jit = $status['jit'] ?? null;
        if ($jit !== null && is_array($jit)) {
            $this->line('  <fg=yellow>JIT</>');
            $this->line("    Enabled:     " . ($jit['enabled'] ? 'Yes' : 'No'));
            $this->line("    Buffer:      {$this->formatBytes($jit['buffer_size'] ?? 0)}");
            $this->newLine();
        }

        // ── Preload Status ───────────────────────────────────────
        $preloadPath = ini_get('opcache.preload');
        if ($preloadPath && $preloadPath !== '') {
            $this->line('  <fg=green>✓ Preload configured: ' . $preloadPath . '</>');
        } else {
            $this->line('  <fg=gray>○ Preload not configured</>');
        }
        $this->newLine();

        // ── Recommendations ──────────────────────────────────────
        $this->line('  <fg=yellow>Recommendations</>');
        $recs = $this->getRecommendations($usedPct, $hitRate, $wastedPct, $preloadPath);
        foreach ($recs as $rec) {
            $this->line("    • {$rec}");
        }
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @param list<string> $recs
     */
    private function getRecommendations(float $usedPct, float $hitRate, float $wastedPct, string|false $preloadPath): array
    {
        $recs = [];

        if ($hitRate < 95) {
            $recs[] = "Hit rate is {$hitRate}% — consider increasing opcache.max_accelerated_files";
        }
        if ($usedPct > 85) {
            $recs[] = "Memory usage is {$usedPct}% — increase opcache.memory_consumption";
        }
        if ($wastedPct > 5) {
            $recs[] = "Wasted memory is {$wastedPct}% — consider running opcache_reset()";
        }
        if (!$preloadPath || $preloadPath === '') {
            $recs[] = "Preload not configured — run `ml preload:generate` and set opcache.preload in php.ini";
        }
        if (empty($recs)) {
            $recs[] = "OPcache configuration looks healthy — no action needed";
        }

        return $recs;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) return "{$bytes} B";
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }
}
