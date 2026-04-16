<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Tests\Unit\Helpers;

use MonkeysLegion\Cli\Helpers\Identifier;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MonkeysLegion\Cli\Helpers\Identifier
 */
final class IdentifierTest extends TestCase
{
    // ── Valid identifiers ──────────────────────────────────────

    public function testValidSimple(): void
    {
        $this->assertTrue(Identifier::isValid('name'));
        $this->assertTrue(Identifier::isValid('userName'));
        $this->assertTrue(Identifier::isValid('user_name'));
        $this->assertTrue(Identifier::isValid('_private'));
    }

    public function testValidWithDigits(): void
    {
        $this->assertTrue(Identifier::isValid('field1'));
        $this->assertTrue(Identifier::isValid('item2count'));
        $this->assertTrue(Identifier::isValid('x123'));
    }

    public function testValidUnderscore(): void
    {
        $this->assertTrue(Identifier::isValid('_'));
        $this->assertTrue(Identifier::isValid('__construct2'));
    }

    // ── Invalid identifiers ───────────────────────────────────

    public function testInvalidStartsWithDigit(): void
    {
        $this->assertFalse(Identifier::isValid('123abc'));
        $this->assertFalse(Identifier::isValid('1name'));
    }

    public function testInvalidContainsSpecialChars(): void
    {
        $this->assertFalse(Identifier::isValid('my-field'));
        $this->assertFalse(Identifier::isValid('my.field'));
        $this->assertFalse(Identifier::isValid('my field'));
        $this->assertFalse(Identifier::isValid('name!'));
    }

    public function testInvalidEmpty(): void
    {
        $this->assertFalse(Identifier::isValid(''));
    }

    // ── Reserved keywords ─────────────────────────────────────

    public function testReservedKeywords(): void
    {
        $this->assertFalse(Identifier::isValid('class'));
        $this->assertFalse(Identifier::isValid('function'));
        $this->assertFalse(Identifier::isValid('return'));
        $this->assertFalse(Identifier::isValid('if'));
        $this->assertFalse(Identifier::isValid('while'));
        $this->assertFalse(Identifier::isValid('match'));
        $this->assertFalse(Identifier::isValid('enum'));
        $this->assertFalse(Identifier::isValid('readonly'));
    }

    public function testReservedKeywordsCaseInsensitive(): void
    {
        $this->assertFalse(Identifier::isValid('CLASS'));
        $this->assertFalse(Identifier::isValid('Return'));
        $this->assertFalse(Identifier::isValid('FUNCTION'));
    }

    public function testNonReservedThatLookSimilar(): void
    {
        $this->assertTrue(Identifier::isValid('className'));
        $this->assertTrue(Identifier::isValid('returnValue'));
        $this->assertTrue(Identifier::isValid('ifCondition'));
    }
}
