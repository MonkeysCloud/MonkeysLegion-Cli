<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Tests\Unit\Console;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MonkeysLegion\Cli\Console\Attributes\Command
 */
final class CommandAttributeTest extends TestCase
{
    public function testSignatureAndDescription(): void
    {
        $attr = new CommandAttr('test:cmd', 'A test');

        $this->assertSame('test:cmd', $attr->signature);
        $this->assertSame('A test', $attr->description);
    }

    public function testDefaultHiddenIsFalse(): void
    {
        $attr = new CommandAttr('x');
        $this->assertFalse($attr->hidden);
    }

    public function testHiddenTrue(): void
    {
        $attr = new CommandAttr('x', hidden: true);
        $this->assertTrue($attr->hidden);
    }

    public function testDefaultAliasesEmpty(): void
    {
        $attr = new CommandAttr('x');
        $this->assertSame([], $attr->aliases);
    }

    public function testAliases(): void
    {
        $attr = new CommandAttr('migrate', aliases: ['m', 'mig']);

        $this->assertCount(2, $attr->aliases);
        $this->assertSame(['m', 'mig'], $attr->aliases);
    }

    public function testDefaultDescription(): void
    {
        $attr = new CommandAttr('x');
        $this->assertSame('', $attr->description);
    }

    public function testAllParametersTogether(): void
    {
        $attr = new CommandAttr(
            signature: 'db:wipe',
            description: 'Wipe DB',
            hidden: true,
            aliases: ['wipe'],
        );

        $this->assertSame('db:wipe', $attr->signature);
        $this->assertSame('Wipe DB', $attr->description);
        $this->assertTrue($attr->hidden);
        $this->assertSame(['wipe'], $attr->aliases);
    }

    public function testReadonlyProperties(): void
    {
        $attr = new CommandAttr('test', 'desc', false, ['a']);

        // Readonly — values set at construction
        $this->assertSame('test', $attr->signature);
        $this->assertSame('desc', $attr->description);
        $this->assertFalse($attr->hidden);
        $this->assertSame(['a'], $attr->aliases);
    }
}
