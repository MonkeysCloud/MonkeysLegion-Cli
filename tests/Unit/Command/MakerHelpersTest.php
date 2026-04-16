<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Tests\Unit\Command;

use MonkeysLegion\Cli\Command\MakerHelpers;
use MonkeysLegion\Cli\Console\Command;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MonkeysLegion\Cli\Command\MakerHelpers
 */
final class MakerHelpersTest extends TestCase
{
    private object $helper;

    protected function setUp(): void
    {
        // Use anonymous class to test the trait
        $this->helper = new class extends Command {
            use MakerHelpers {
                ensureSuffix as public;
                removeSuffix as public;
                toPascalCase as public;
                toSnakeCase as public;
                toCamelCase as public;
            }

            protected function handle(): int
            {
                return self::SUCCESS;
            }
        };
    }

    // ── ensureSuffix ──────────────────────────────────────────────

    public function testEnsureSuffixAddsWhenMissing(): void
    {
        $this->assertSame('UserService', $this->helper->ensureSuffix('User', 'Service'));
    }

    public function testEnsureSuffixDoesNotDouble(): void
    {
        $this->assertSame('UserService', $this->helper->ensureSuffix('UserService', 'Service'));
    }

    public function testEnsureSuffixEmpty(): void
    {
        $this->assertSame('Controller', $this->helper->ensureSuffix('', 'Controller'));
    }

    // ── removeSuffix ──────────────────────────────────────────────

    public function testRemoveSuffixRemoves(): void
    {
        $this->assertSame('User', $this->helper->removeSuffix('UserService', 'Service'));
    }

    public function testRemoveSuffixNoop(): void
    {
        $this->assertSame('User', $this->helper->removeSuffix('User', 'Service'));
    }

    // ── toPascalCase ──────────────────────────────────────────────

    public function testToPascalCaseFromSnake(): void
    {
        $this->assertSame('UserProfile', $this->helper->toPascalCase('user_profile'));
    }

    public function testToPascalCaseFromKebab(): void
    {
        $this->assertSame('UserProfile', $this->helper->toPascalCase('user-profile'));
    }

    public function testToPascalCaseFromSpaces(): void
    {
        $this->assertSame('UserProfile', $this->helper->toPascalCase('user profile'));
    }

    public function testToPascalCaseAlreadyPascal(): void
    {
        $this->assertSame('UserProfile', $this->helper->toPascalCase('UserProfile'));
    }

    // ── toSnakeCase ───────────────────────────────────────────────

    public function testToSnakeCaseFromPascal(): void
    {
        $this->assertSame('user_profile', $this->helper->toSnakeCase('UserProfile'));
    }

    public function testToSnakeCaseFromCamel(): void
    {
        $this->assertSame('user_profile', $this->helper->toSnakeCase('userProfile'));
    }

    public function testToSnakeCaseAlreadySnake(): void
    {
        $this->assertSame('user_profile', $this->helper->toSnakeCase('user_profile'));
    }

    // ── toCamelCase ───────────────────────────────────────────────

    public function testToCamelCaseFromSnake(): void
    {
        $this->assertSame('userProfile', $this->helper->toCamelCase('user_profile'));
    }

    public function testToCamelCaseFromPascal(): void
    {
        $this->assertSame('userProfile', $this->helper->toCamelCase('UserProfile'));
    }
}
