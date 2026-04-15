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
    }

    public function testConstructWithLabel(): void
    {
        $bar = new ProgressBar(50, label: 'Loading');
        $this->assertInstanceOf(ProgressBar::class, $bar);
    }

    public function testConstructWithCustomWidth(): void
    {
        $bar = new ProgressBar(50, barWidth: 40);
        $this->assertInstanceOf(ProgressBar::class, $bar);
    }

    public function testMinimumBarWidth(): void
    {
        // Width < 10 should be clamped to 10
        $bar = new ProgressBar(50, barWidth: 3);
        $this->assertInstanceOf(ProgressBar::class, $bar);
    }

    public function testStartDoesNotThrow(): void
    {
        $bar = new ProgressBar(10);
        $bar->start();
        $this->assertTrue(true);
    }

    public function testAdvanceDoesNotThrow(): void
    {
        $bar = new ProgressBar(10);
        $bar->start();
        $bar->advance(3);
        $this->assertTrue(true);
    }

    public function testAdvanceWithoutStartDoesNotThrow(): void
    {
        $bar = new ProgressBar(10);
        $bar->advance(); // auto-starts
        $this->assertTrue(true);
    }

    public function testSetProgressDoesNotThrow(): void
    {
        $bar = new ProgressBar(10);
        $bar->start();
        $bar->setProgress(5);
        $this->assertTrue(true);
    }

    public function testFinishDoesNotThrow(): void
    {
        $bar = new ProgressBar(10);
        $bar->start();
        $bar->advance(10);
        $bar->finish();
        $this->assertTrue(true);
    }

    public function testZeroTotal(): void
    {
        $bar = new ProgressBar(0);
        $bar->start();
        $bar->finish();
        $this->assertTrue(true);
    }
}
