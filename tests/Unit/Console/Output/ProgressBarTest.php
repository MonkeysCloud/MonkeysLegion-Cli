<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Tests\Unit\Console\Output;

use MonkeysLegion\Cli\Console\Output\ProgressBar;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MonkeysLegion\Cli\Console\Output\ProgressBar
 */
final class ProgressBarTest extends TestCase
{
    public function testCanConstruct(): void
    {
        $bar = new ProgressBar(100);
        $this->assertInstanceOf(ProgressBar::class, $bar);
        $this->assertSame(0, $bar->current);
        $this->assertFalse($bar->started);
    }

    public function testConstructWithLabel(): void
    {
        $bar = new ProgressBar(50, label: 'Loading');
        $this->assertSame(0, $bar->current);
    }

    public function testBarWidthClampedToMinimum(): void
    {
        // barWidth set hook enforces min 10
        $bar = new ProgressBar(50, barWidth: 3);
        $this->assertSame(10, $bar->barWidth);
    }

    public function testBarWidthAcceptsValid(): void
    {
        $bar = new ProgressBar(50, barWidth: 40);
        $this->assertSame(40, $bar->barWidth);
    }

    public function testStartSetsStarted(): void
    {
        $bar = new ProgressBar(10);
        $bar->start();
        $this->assertTrue($bar->started);
        $this->assertSame(0, $bar->current);
    }

    public function testAdvanceClampsCurrent(): void
    {
        $bar = new ProgressBar(10);
        $bar->start();
        $bar->advance(3);
        $this->assertSame(3, $bar->current);

        // Advance past total — clamped by set hook
        $bar->advance(100);
        $this->assertSame(10, $bar->current);
    }

    public function testAdvanceAutoStarts(): void
    {
        $bar = new ProgressBar(10);
        $this->assertFalse($bar->started);
        $bar->advance();
        $this->assertTrue($bar->started);
        $this->assertSame(1, $bar->current);
    }

    public function testSetProgressClampsValue(): void
    {
        $bar = new ProgressBar(10);
        $bar->start();
        $bar->setProgress(5);
        $this->assertSame(5, $bar->current);

        // Negative — clamped to 0 by set hook
        $bar->setProgress(-5);
        $this->assertSame(0, $bar->current);

        // Over total — clamped by set hook
        $bar->setProgress(999);
        $this->assertSame(10, $bar->current);
    }

    public function testPercentIsComputed(): void
    {
        $bar = new ProgressBar(10);
        $bar->start();
        $this->assertEqualsWithDelta(0.0, $bar->percent, 0.001);

        $bar->advance(5);
        $this->assertEqualsWithDelta(0.5, $bar->percent, 0.001);

        $bar->advance(5);
        $this->assertEqualsWithDelta(1.0, $bar->percent, 0.001);
    }

    public function testFinishSetsFull(): void
    {
        $bar = new ProgressBar(10);
        $bar->start();
        $bar->finish();
        $this->assertSame(10, $bar->current);
        $this->assertEqualsWithDelta(1.0, $bar->percent, 0.001);
    }

    public function testZeroTotalPercentIsOne(): void
    {
        $bar = new ProgressBar(0);
        $bar->start();
        $this->assertEqualsWithDelta(1.0, $bar->percent, 0.001);
        $bar->finish();
        $this->assertTrue(true);
    }
}
