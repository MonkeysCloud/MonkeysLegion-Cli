<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Tests\Unit\Console;

use MonkeysLegion\Cli\Console\Command;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the base Command class (argument, option, output helpers).
 *
 * @covers \MonkeysLegion\Cli\Console\Command
 */
final class CommandTest extends TestCase
{
    private function makeCommand(): TestableCommand
    {
        return new TestableCommand();
    }

    // ── Constants ──────────────────────────────────────────────────

    public function testSuccessConstant(): void
    {
        $this->assertSame(0, Command::SUCCESS);
    }

    public function testFailureConstant(): void
    {
        $this->assertSame(1, Command::FAILURE);
    }

    // ── __invoke ──────────────────────────────────────────────────

    public function testInvokeCallsHandle(): void
    {
        $cmd = $this->makeCommand();
        $this->assertSame(0, $cmd());
    }

    // ── argument() ───────────────────────────────────────────────

    public function testArgumentReturnsValue(): void
    {
        global $argv;
        $original = $argv;

        $argv = ['ml', 'make:entity', 'User', 'extra'];
        $cmd  = $this->makeCommand();

        $this->assertSame('User', $cmd->callArgument(0));
        $this->assertSame('extra', $cmd->callArgument(1));
        $this->assertNull($cmd->callArgument(5));

        $argv = $original;
    }

    public function testArgumentReturnsNullWhenNoArgv(): void
    {
        global $argv;
        $original = $argv;

        $argv = null;
        $cmd  = $this->makeCommand();
        $this->assertNull($cmd->callArgument(0));

        $argv = $original;
    }

    // ── option() ─────────────────────────────────────────────────

    public function testOptionLongEquals(): void
    {
        global $argv;
        $original = $argv;

        $argv = ['ml', 'cmd', '--fields=name:string,email:string'];
        $cmd  = $this->makeCommand();

        $this->assertSame('name:string,email:string', $cmd->callOption('fields'));

        $argv = $original;
    }

    public function testOptionLongSpace(): void
    {
        global $argv;
        $original = $argv;

        $argv = ['ml', 'cmd', '--name', 'Alice'];
        $cmd  = $this->makeCommand();

        $this->assertSame('Alice', $cmd->callOption('name'));

        $argv = $original;
    }

    public function testOptionFlag(): void
    {
        global $argv;
        $original = $argv;

        $argv = ['ml', 'cmd', '--force'];
        $cmd  = $this->makeCommand();

        $this->assertTrue($cmd->callOption('force'));

        $argv = $original;
    }

    public function testOptionShort(): void
    {
        global $argv;
        $original = $argv;

        $argv = ['ml', 'cmd', '-f'];
        $cmd  = $this->makeCommand();

        $this->assertTrue($cmd->callOption('force'));

        $argv = $original;
    }

    public function testOptionDefault(): void
    {
        global $argv;
        $original = $argv;

        $argv = ['ml', 'cmd'];
        $cmd  = $this->makeCommand();

        $this->assertSame('default', $cmd->callOption('missing', 'default'));
        $this->assertNull($cmd->callOption('missing'));

        $argv = $original;
    }

    public function testOptionReturnsDefaultWhenNoArgv(): void
    {
        global $argv;
        $original = $argv;

        $argv = null;
        $cmd  = $this->makeCommand();

        $this->assertSame('fallback', $cmd->callOption('x', 'fallback'));

        $argv = $original;
    }

    // ── hasOption() ──────────────────────────────────────────────

    public function testHasOptionTrue(): void
    {
        global $argv;
        $original = $argv;

        $argv = ['ml', 'cmd', '--verbose', '--name=Alice'];
        $cmd  = $this->makeCommand();

        $this->assertTrue($cmd->callHasOption('verbose'));
        $this->assertTrue($cmd->callHasOption('name'));

        $argv = $original;
    }

    public function testHasOptionFalse(): void
    {
        global $argv;
        $original = $argv;

        $argv = ['ml', 'cmd'];
        $cmd  = $this->makeCommand();

        $this->assertFalse($cmd->callHasOption('verbose'));

        $argv = $original;
    }

    public function testHasOptionShort(): void
    {
        global $argv;
        $original = $argv;

        $argv = ['ml', 'cmd', '-v'];
        $cmd  = $this->makeCommand();

        $this->assertTrue($cmd->callHasOption('verbose'));

        $argv = $original;
    }

    public function testHasOptionReturnsWhenNoArgv(): void
    {
        global $argv;
        $original = $argv;

        $argv = null;
        $cmd  = $this->makeCommand();

        $this->assertFalse($cmd->callHasOption('anything'));

        $argv = $original;
    }

    // ── allOptions() ─────────────────────────────────────────────

    public function testAllOptions(): void
    {
        global $argv;
        $original = $argv;

        $argv = ['ml', 'cmd', '--name=Alice', '--force', '--count', '5'];
        $cmd  = $this->makeCommand();

        $opts = $cmd->callAllOptions();

        $this->assertSame('Alice', $opts['name']);
        $this->assertTrue($opts['force']);
        $this->assertSame('5', $opts['count']);

        $argv = $original;
    }

    public function testAllOptionsEmpty(): void
    {
        global $argv;
        $original = $argv;

        $argv = ['ml', 'cmd'];
        $cmd  = $this->makeCommand();

        $this->assertSame([], $cmd->callAllOptions());

        $argv = $original;
    }

    public function testAllOptionsReturnsEmptyWhenNoArgv(): void
    {
        global $argv;
        $original = $argv;

        $argv = null;
        $cmd  = $this->makeCommand();

        $this->assertSame([], $cmd->callAllOptions());

        $argv = $original;
    }

    // ── Output helpers (use fwrite to STDOUT, so we verify no exceptions) ──

    public function testInfoDoesNotThrow(): void
    {
        ob_start();
        $cmd = $this->makeCommand();
        $cmd->callInfo('Test info');
        ob_end_clean();

        $this->assertTrue(true); // No exception
    }

    public function testLineDoesNotThrow(): void
    {
        ob_start();
        $cmd = $this->makeCommand();
        $cmd->callLine('Plain line');
        ob_end_clean();

        $this->assertTrue(true);
    }

    public function testWarnDoesNotThrow(): void
    {
        ob_start();
        $cmd = $this->makeCommand();
        $cmd->callWarn('Warning');
        ob_end_clean();

        $this->assertTrue(true);
    }

    public function testCommentDoesNotThrow(): void
    {
        ob_start();
        $cmd = $this->makeCommand();
        $cmd->callComment('A comment');
        ob_end_clean();

        $this->assertTrue(true);
    }

    public function testAlertDoesNotThrow(): void
    {
        ob_start();
        $cmd = $this->makeCommand();
        $cmd->callAlert('Alert msg');
        ob_end_clean();

        $this->assertTrue(true);
    }

    public function testNewLineDoesNotThrow(): void
    {
        ob_start();
        $cmd = $this->makeCommand();
        $cmd->callNewLine(2);
        ob_end_clean();

        $this->assertTrue(true);
    }

    public function testFailReturnsFailure(): void
    {
        ob_start();
        $cmd = $this->makeCommand();
        $this->assertSame(1, $cmd->callFail('Error'));
        ob_end_clean();
    }

    // ── safeQuery ────────────────────────────────────────────────

    public function testSafeQueryThrowsOnFailure(): void
    {
        $cmd = $this->makeCommand();
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('query')->willReturn(false);
        $pdo->method('errorInfo')->willReturn([null, null, 'Mock error']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Query failed');

        $ref = new \ReflectionMethod($cmd, 'safeQuery');
        $ref->invoke($cmd, $pdo, 'SELECT 1');
    }

    public function testSafeQueryReturnsStatement(): void
    {
        $cmd  = $this->makeCommand();
        $stmt = $this->createMock(\PDOStatement::class);
        $pdo  = $this->createMock(\PDO::class);
        $pdo->method('query')->willReturn($stmt);

        $ref    = new \ReflectionMethod($cmd, 'safeQuery');
        $result = $ref->invoke($cmd, $pdo, 'SELECT 1');

        $this->assertSame($stmt, $result);
    }
}
