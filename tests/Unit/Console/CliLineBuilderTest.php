<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Tests\Unit\Console;

use MonkeysLegion\Cli\Console\Traits\Cli;
use MonkeysLegion\Cli\Console\Traits\CliLineBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MonkeysLegion\Cli\Console\Traits\CliLineBuilder
 */
final class CliLineBuilderTest extends TestCase
{
    // ── Building strings ──────────────────────────────────────────

    public function testEmptyBuilder(): void
    {
        $builder = new CliLineBuilder();
        $this->assertTrue($builder->isEmpty);
        $this->assertSame(0, $builder->count);
        $this->assertSame('', $builder->build());
    }

    public function testAddSetsColor(): void
    {
        $result = (new CliLineBuilder())
            ->add('Hello', 'red')
            ->build();

        $this->assertStringContainsString("\033[31m", $result);
        $this->assertStringContainsString('Hello', $result);
        $this->assertStringContainsString("\033[0m", $result);
    }

    public function testAddWithStyle(): void
    {
        $result = (new CliLineBuilder())
            ->add('Bold', 'white', 'bold')
            ->build();

        $this->assertStringContainsString("\033[37;1m", $result);
    }

    public function testAddWithMultipleStyles(): void
    {
        $result = (new CliLineBuilder())
            ->add('Styled', 'green', ['bold', 'underline'])
            ->build();

        $this->assertStringContainsString('1', $result); // bold code
        $this->assertStringContainsString('4', $result); // underline code
    }

    public function testAddWithBackground(): void
    {
        $result = (new CliLineBuilder())
            ->add('BG', 'white', null, 'red')
            ->build();

        $this->assertStringContainsString('41', $result); // red bg code
    }

    public function testPlainNoColor(): void
    {
        $result = (new CliLineBuilder())
            ->plain('no color')
            ->build();

        $this->assertSame('no color', $result);
        $this->assertStringNotContainsString("\033[", $result);
    }

    public function testSpace(): void
    {
        $result = (new CliLineBuilder())
            ->space(3)
            ->build();

        $this->assertSame('   ', $result);
    }

    public function testNewline(): void
    {
        $result = (new CliLineBuilder())
            ->newline()
            ->build();

        $this->assertSame("\n", $result);
    }

    // ── Semantic helpers ──────────────────────────────────────────

    public function testSuccess(): void
    {
        $result = (new CliLineBuilder())->success('OK')->build();
        $this->assertStringContainsString('OK', $result);
        $this->assertStringContainsString("\033[32", $result); // green
    }

    public function testError(): void
    {
        $result = (new CliLineBuilder())->error('FAIL')->build();
        $this->assertStringContainsString("\033[31", $result); // red
    }

    public function testWarning(): void
    {
        $result = (new CliLineBuilder())->warning('WARN')->build();
        $this->assertStringContainsString("\033[33", $result); // yellow
    }

    public function testInfo(): void
    {
        $result = (new CliLineBuilder())->info('INFO')->build();
        $this->assertStringContainsString("\033[36", $result); // cyan
    }

    public function testMuted(): void
    {
        $result = (new CliLineBuilder())->muted('DIM')->build();
        $this->assertStringContainsString("\033[90", $result); // gray
    }

    // ── Chaining ──────────────────────────────────────────────────

    public function testMultipleSegments(): void
    {
        $builder = (new CliLineBuilder())
            ->add('A ', 'red')
            ->add('B ', 'green')
            ->add('C', 'blue');

        $this->assertSame(3, $builder->count);
        $plain = $builder->toPlainText();
        $this->assertSame('A B C', $plain);
    }

    public function testToPlainText(): void
    {
        $result = (new CliLineBuilder())
            ->add('Hello ', 'red', 'bold')
            ->add('World', 'green')
            ->toPlainText();

        $this->assertSame('Hello World', $result);
    }

    // ── Clear ─────────────────────────────────────────────────────

    public function testClear(): void
    {
        $builder = (new CliLineBuilder())
            ->add('X', 'red');

        $this->assertSame(1, $builder->count);

        $builder->clear();
        $this->assertSame(0, $builder->count);
        $this->assertTrue($builder->isEmpty);
    }

    // ── Unknown color falls back to white ─────────────────────────

    public function testUnknownColorFallsBackToWhite(): void
    {
        $result = (new CliLineBuilder())
            ->add('Test', 'nonexistent')
            ->build();

        $this->assertStringContainsString("\033[37m", $result); // white
    }
}
