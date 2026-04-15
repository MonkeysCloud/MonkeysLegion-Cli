<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Console\Output;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Renders beautiful tables with UTF-8 box-drawing characters,
 * auto-fitted column widths, and color support.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class TableRenderer
{
    // ── Box chars ─────────────────────────────────────────────────
    private const string TL = '┌';
    private const string TR = '┐';
    private const string BL = '└';
    private const string BR = '┘';
    private const string H  = '─';
    private const string V  = '│';
    private const string LT = '├';
    private const string RT = '┤';
    private const string TT = '┬';
    private const string BT = '┴';
    private const string CR = '┼';

    /**
     * Render a table to STDOUT.
     *
     * @param list<string>              $headers Column headers
     * @param list<list<string>>        $rows    Data rows
     * @param array<int, string>        $align   Column alignment: 'l', 'r', 'c' (default: 'l')
     * @param array<int, string|null>   $rowColors Row-index => ANSI color code
     */
    public function render(
        array $headers,
        array $rows,
        array $align = [],
        array $rowColors = [],
    ): void {
        fwrite(STDOUT, $this->build($headers, $rows, $align, $rowColors));
    }

    /**
     * Build the full table string.
     *
     * @param list<string>           $headers
     * @param list<list<string>>     $rows
     * @param array<int, string>     $align
     * @param array<int, string|null> $rowColors
     */
    public function build(
        array $headers,
        array $rows,
        array $align = [],
        array $rowColors = [],
    ): string {
        if ($headers === []) {
            return '';
        }

        $colCount = count($headers);
        $widths   = $this->calculateWidths($headers, $rows, $colCount);
        $out      = '';

        // ── Top border ───────────────────────────────────────────
        $out .= self::TL;
        for ($i = 0; $i < $colCount; $i++) {
            $out .= str_repeat(self::H, $widths[$i] + 2);
            $out .= $i < $colCount - 1 ? self::TT : self::TR;
        }
        $out .= PHP_EOL;

        // ── Header row ───────────────────────────────────────────
        $out .= self::V;
        for ($i = 0; $i < $colCount; $i++) {
            $out .= ' ' . $this->colorize(
                $this->pad($headers[$i], $widths[$i], 'l'),
                "\033[1;37m", // bold white
            ) . ' ' . self::V;
        }
        $out .= PHP_EOL;

        // ── Separator ────────────────────────────────────────────
        $out .= self::LT;
        for ($i = 0; $i < $colCount; $i++) {
            $out .= str_repeat(self::H, $widths[$i] + 2);
            $out .= $i < $colCount - 1 ? self::CR : self::RT;
        }
        $out .= PHP_EOL;

        // ── Data rows ────────────────────────────────────────────
        foreach ($rows as $ri => $row) {
            $color = $rowColors[$ri] ?? null;
            $out  .= self::V;

            for ($i = 0; $i < $colCount; $i++) {
                $cell      = (string) ($row[$i] ?? '');
                $alignment = $align[$i] ?? 'l';
                $padded    = $this->pad($cell, $widths[$i], $alignment);

                $out .= ' '
                    . ($color !== null ? $this->colorize($padded, $color) : $padded)
                    . ' ' . self::V;
            }

            $out .= PHP_EOL;
        }

        // ── Bottom border ────────────────────────────────────────
        $out .= self::BL;
        for ($i = 0; $i < $colCount; $i++) {
            $out .= str_repeat(self::H, $widths[$i] + 2);
            $out .= $i < $colCount - 1 ? self::BT : self::BR;
        }
        $out .= PHP_EOL;

        return $out;
    }

    // ── Internal ──────────────────────────────────────────────────

    /**
     * Calculate column widths from headers + rows.
     *
     * @param list<string>        $headers
     * @param list<list<string>>  $rows
     * @return list<int>
     */
    private function calculateWidths(array $headers, array $rows, int $colCount): array
    {
        $widths = [];

        for ($i = 0; $i < $colCount; $i++) {
            $widths[$i] = $this->visibleLength($headers[$i]);
        }

        foreach ($rows as $row) {
            for ($i = 0; $i < $colCount; $i++) {
                $len = $this->visibleLength((string) ($row[$i] ?? ''));
                if ($len > $widths[$i]) {
                    $widths[$i] = $len;
                }
            }
        }

        return $widths;
    }

    /**
     * Pad text to the given width with alignment.
     */
    private function pad(string $text, int $width, string $alignment): string
    {
        $len  = $this->visibleLength($text);
        $diff = $width - $len;

        if ($diff <= 0) {
            return $text;
        }

        return match ($alignment) {
            'r'     => str_repeat(' ', $diff) . $text,
            'c'     => str_repeat(' ', intdiv($diff, 2)) . $text . str_repeat(' ', $diff - intdiv($diff, 2)),
            default => $text . str_repeat(' ', $diff),
        };
    }

    /**
     * Strip ANSI escape codes to calculate visible length.
     */
    private function visibleLength(string $text): int
    {
        $stripped = (string) preg_replace('/\033\[[0-9;]*m/', '', $text);

        return mb_strlen($stripped);
    }

    /**
     * Wrap text in an ANSI color code.
     */
    private function colorize(string $text, string $ansi): string
    {
        return $ansi . $text . "\033[0m";
    }
}
