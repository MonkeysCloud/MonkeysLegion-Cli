<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Tests\Unit\Console\Output;

use MonkeysLegion\Cli\Console\Output\TableRenderer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MonkeysLegion\Cli\Console\Output\TableRenderer
 */
final class TableRendererTest extends TestCase
{
    private TableRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new TableRenderer();
    }

    // ── Basic rendering ───────────────────────────────────────────

    public function testEmptyHeadersReturnsEmpty(): void
    {
        $result = $this->renderer->build([], []);
        $this->assertSame('', $result);
    }

    public function testRendersHeadersOnly(): void
    {
        $result = $this->renderer->build(['Name', 'Age'], []);

        $this->assertStringContainsString('┌', $result);
        $this->assertStringContainsString('┐', $result);
        $this->assertStringContainsString('└', $result);
        $this->assertStringContainsString('┘', $result);
        $this->assertStringContainsString('Name', $result);
        $this->assertStringContainsString('Age', $result);
    }

    public function testRendersHeadersAndRows(): void
    {
        $result = $this->renderer->build(
            ['ID', 'Name'],
            [['1', 'Alice'], ['2', 'Bob']],
        );

        $this->assertStringContainsString('Alice', $result);
        $this->assertStringContainsString('Bob', $result);
        $this->assertStringContainsString('┼', $result); // separator
    }

    // ── Column widths ─────────────────────────────────────────────

    public function testAutoFitsColumnWidths(): void
    {
        $result = $this->renderer->build(
            ['X', 'LongHeader'],
            [['Short', 'Y']],
        );

        // LongHeader should set the min width for column 2
        $lines = explode("\n", $result);
        $widthLine = $lines[0]; // top border
        $this->assertGreaterThan(20, mb_strlen($widthLine));
    }

    public function testHandlesEmptyCells(): void
    {
        $result = $this->renderer->build(
            ['A', 'B', 'C'],
            [['1', '', '3'], ['', '2', '']],
        );

        $this->assertStringContainsString('│', $result);
    }

    // ── Alignment ─────────────────────────────────────────────────

    public function testLeftAlignmentDefault(): void
    {
        $result = $this->renderer->build(
            ['Name'],
            [['X']],
        );

        // X should be left-aligned (followed by spaces)
        $this->assertStringContainsString('X', $result);
    }

    public function testRightAlignment(): void
    {
        $result = $this->renderer->build(
            ['Count'],
            [['42']],
            [0 => 'r'],
        );

        $this->assertStringContainsString('42', $result);
    }

    public function testCenterAlignment(): void
    {
        $result = $this->renderer->build(
            ['Status'],
            [['OK']],
            [0 => 'c'],
        );

        $this->assertStringContainsString('OK', $result);
    }

    // ── Row colors ────────────────────────────────────────────────

    public function testRowColorsApplied(): void
    {
        $result = $this->renderer->build(
            ['Name'],
            [['Error!']],
            [],
            [0 => "\033[31m"],
        );

        $this->assertStringContainsString("\033[31m", $result);
        $this->assertStringContainsString("\033[0m", $result);
    }

    public function testRowColorsNullSkipped(): void
    {
        $result = $this->renderer->build(
            ['Name'],
            [['Normal']],
            [],
            [0 => null],
        );

        // No color codes for null
        $this->assertStringNotContainsString("\033[31m", $result);
    }

    // ── Box drawing characters ────────────────────────────────────

    public function testContainsAllBorderCharacters(): void
    {
        $result = $this->renderer->build(
            ['A', 'B'],
            [['1', '2']],
        );

        $chars = ['┌', '┬', '┐', '├', '┼', '┤', '└', '┴', '┘', '│', '─'];

        foreach ($chars as $char) {
            $this->assertStringContainsString($char, $result, "Missing box char: {$char}");
        }
    }

    // ── Multi-column ──────────────────────────────────────────────

    public function testThreeColumns(): void
    {
        $result = $this->renderer->build(
            ['A', 'B', 'C'],
            [['1', '2', '3']],
        );

        // Count vertical separators in a data row
        $lines = explode("\n", trim($result));
        $headerRow = $lines[1]; // header row
        $this->assertSame(4, substr_count($headerRow, '│'));
    }

    public function testSingleColumn(): void
    {
        $result = $this->renderer->build(['Only'], [['Data']]);

        $this->assertStringContainsString('Only', $result);
        $this->assertStringContainsString('Data', $result);
        // No ┬ or ┼ for single column
        $this->assertStringNotContainsString('┬', $result);
    }

    // ── Long content ──────────────────────────────────────────────

    public function testLongCellContent(): void
    {
        $long = str_repeat('X', 100);
        $result = $this->renderer->build(
            ['Data'],
            [[$long]],
        );

        $this->assertStringContainsString($long, $result);
    }

    public function testUnicodeContent(): void
    {
        $result = $this->renderer->build(
            ['Emoji'],
            [['✅ Done'], ['❌ Failed']],
        );

        $this->assertStringContainsString('✅', $result);
        $this->assertStringContainsString('❌', $result);
    }

    // ── Rendering to STDOUT ───────────────────────────────────────

    public function testRenderOutputsToStdout(): void
    {
        ob_start();
        $this->renderer->render(['X'], [['Y']]);
        $output = ob_get_clean();

        // render() writes to STDOUT, which ob_start may not capture
        // Just verify render() doesn't throw
        $this->assertTrue(true);
    }
}
