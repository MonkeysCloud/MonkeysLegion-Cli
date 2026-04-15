<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Tests\Unit\Config;

use MonkeysLegion\Cli\Config\FieldType;
use MonkeysLegion\Cli\Config\FieldTypeConfig;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MonkeysLegion\Cli\Config\FieldTypeConfig
 * @covers \MonkeysLegion\Cli\Config\FieldType
 */
final class FieldTypeConfigTest extends TestCase
{
    private FieldTypeConfig $config;

    protected function setUp(): void
    {
        $this->config = new FieldTypeConfig();
    }

    // ── all() ─────────────────────────────────────────────────────

    public function testAllReturnsCases(): void
    {
        $all = $this->config->all();
        $this->assertNotEmpty($all);
        $this->assertContainsOnlyInstancesOf(FieldType::class, $all);
    }

    public function testAllContainsCommonTypes(): void
    {
        $all    = $this->config->all();
        $values = array_map(fn(FieldType $ft) => $ft->value, $all);

        $expected = ['string', 'integer', 'boolean', 'text', 'json', 'datetime', 'uuid'];

        foreach ($expected as $type) {
            $this->assertContains($type, $values, "Missing type: {$type}");
        }
    }

    // ── fromString() ──────────────────────────────────────────────

    public function testFromStringValid(): void
    {
        $this->assertSame(FieldType::STRING, $this->config->fromString('string'));
        $this->assertSame(FieldType::INTEGER, $this->config->fromString('integer'));
        $this->assertSame(FieldType::BOOLEAN, $this->config->fromString('boolean'));
        $this->assertSame(FieldType::UUID, $this->config->fromString('uuid'));
        $this->assertSame(FieldType::JSON, $this->config->fromString('json'));
    }

    public function testFromStringInvalidThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown field type: nonexistent');
        $this->config->fromString('nonexistent');
    }

    // ── FieldType enum ────────────────────────────────────────────

    public function testEnumStringValues(): void
    {
        $this->assertSame('string', FieldType::STRING->value);
        $this->assertSame('integer', FieldType::INTEGER->value);
        $this->assertSame('bigInt', FieldType::BIG_INT->value);
        $this->assertSame('datetime', FieldType::DATETIME->value);
    }

    public function testEnumFromBackedValue(): void
    {
        $this->assertSame(FieldType::BOOLEAN, FieldType::from('boolean'));
        $this->assertSame(FieldType::DECIMAL, FieldType::from('decimal'));
    }

    public function testEnumTryFromInvalid(): void
    {
        $this->assertNull(FieldType::tryFrom('does_not_exist'));
    }

    public function testEnumCasesCount(): void
    {
        // Verify we have a reasonable number of types
        $this->assertGreaterThan(20, count(FieldType::cases()));
    }

    public function testSpecialTypes(): void
    {
        $this->assertSame('enum', FieldType::ENUM->value);
        $this->assertSame('set', FieldType::SET->value);
        $this->assertSame('geometry', FieldType::GEOMETRY->value);
        $this->assertSame('ipAddress', FieldType::IP_ADDRESS->value);
    }
}
