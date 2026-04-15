<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Tests\Unit\Console;

use MonkeysLegion\Cli\Console\Output\Spinner;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MonkeysLegion\Cli\Console\Output\Spinner
 */
final class SpinnerTest extends TestCase
{
    public function testCanConstruct(): void
    {
        $spinner = new Spinner('Testing');
        $this->assertInstanceOf(Spinner::class, $spinner);
    }

    public function testDefaultMessage(): void
    {
        $spinner = new Spinner();
        $this->assertInstanceOf(Spinner::class, $spinner);
    }

    public function testRunReturnsCallbackResult(): void
    {
        $spinner = new Spinner('Compute');
        $result  = $spinner->run(fn() => 42);

        $this->assertSame(42, $result);
    }

    public function testRunReturnsStringResult(): void
    {
        $spinner = new Spinner('Test');
        $result  = $spinner->run(fn() => 'hello');

        $this->assertSame('hello', $result);
    }

    public function testRunReturnsArrayResult(): void
    {
        $spinner = new Spinner();
        $result  = $spinner->run(fn() => [1, 2, 3]);

        $this->assertSame([1, 2, 3], $result);
    }

    public function testRunReturnsNull(): void
    {
        $spinner = new Spinner();
        $result  = $spinner->run(fn() => null);

        $this->assertNull($result);
    }

    public function testRunRethrowsException(): void
    {
        $spinner = new Spinner('Fail');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('test error');

        $spinner->run(fn() => throw new \RuntimeException('test error'));
    }

    public function testTickDoesNotThrow(): void
    {
        $spinner = new Spinner('Tick');
        $frame   = 0;

        $spinner->tick($frame);
        $this->assertSame(1, $frame);

        $spinner->tick($frame);
        $this->assertSame(2, $frame);
    }

    public function testSuccessDoesNotThrow(): void
    {
        $spinner = new Spinner('OK');
        $spinner->success();
        $this->assertTrue(true);
    }

    public function testSuccessWithCustomMessage(): void
    {
        $spinner = new Spinner('Task');
        $spinner->success('Custom message');
        $this->assertTrue(true);
    }

    public function testFailDoesNotThrow(): void
    {
        $spinner = new Spinner('Nope');
        $spinner->fail();
        $this->assertTrue(true);
    }

    public function testFailWithCustomMessage(): void
    {
        $spinner = new Spinner();
        $spinner->fail('Custom fail');
        $this->assertTrue(true);
    }
}
