<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Tests\Unit\Config;

use MonkeysLegion\Cli\Config\EntityConfig;
use MonkeysLegion\Cli\Config\FieldTypeConfig;
use MonkeysLegion\Cli\Config\PhpTypeMap;
use MonkeysLegion\Cli\Config\RelationInverseMap;
use MonkeysLegion\Cli\Config\RelationKeywordMap;
use MonkeysLegion\Cli\Config\RelationKind;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MonkeysLegion\Cli\Config\EntityConfig
 * @covers \MonkeysLegion\Cli\Config\PhpTypeMap
 * @covers \MonkeysLegion\Cli\Config\RelationKeywordMap
 * @covers \MonkeysLegion\Cli\Config\RelationInverseMap
 * @covers \MonkeysLegion\Cli\Config\RelationKind
 */
final class EntityConfigTest extends TestCase
{
    // ── EntityConfig ──────────────────────────────────────────

    public function testEntityConfigReadonly(): void
    {
        $config = new EntityConfig(
            new FieldTypeConfig(),
            new PhpTypeMap(),
            new RelationKeywordMap(),
            new RelationInverseMap(),
        );

        $this->assertNotNull($config->fieldTypes);
        $this->assertNotNull($config->phpTypeMap);
        $this->assertNotNull($config->relationKeywordMap);
        $this->assertNotNull($config->relationInverseMap);
    }

    // ── PhpTypeMap ────────────────────────────────────────────

    public function testPhpTypeMapAll(): void
    {
        $map = new PhpTypeMap();
        $all = $map->all();

        $this->assertNotEmpty($all);
        $this->assertSame('string', $all['string']);
        $this->assertSame('int', $all['integer']);
        $this->assertSame('float', $all['decimal']);
        $this->assertSame('bool', $all['boolean']);
        $this->assertSame('array', $all['json']);
        $this->assertSame('\\DateTimeImmutable', $all['datetime']);
    }

    public function testPhpTypeMapSpecificTypes(): void
    {
        $map = new PhpTypeMap();

        $this->assertSame('int', $map->all()['bigInt']);
        $this->assertSame('int', $map->all()['unsignedBigInt']);
        $this->assertSame('string', $map->all()['uuid']);
        $this->assertSame('string', $map->all()['ipAddress']);
        $this->assertSame('string', $map->all()['macAddress']);
    }

    // ── RelationKeywordMap ────────────────────────────────────

    public function testRelationKeywordMapAll(): void
    {
        $map = new RelationKeywordMap();
        $all = $map->all();

        $this->assertCount(4, $all);
        $this->assertSame('OneToOne', $all['oneToOne']);
        $this->assertSame('OneToMany', $all['oneToMany']);
        $this->assertSame('ManyToOne', $all['manyToOne']);
        $this->assertSame('ManyToMany', $all['manyToMany']);
    }

    public function testRelationKeywordMapGetAttribute(): void
    {
        $map = new RelationKeywordMap();

        $this->assertSame('OneToOne', $map->getAttribute(RelationKind::ONE_TO_ONE));
        $this->assertSame('ManyToMany', $map->getAttribute(RelationKind::MANY_TO_MANY));
    }

    public function testRelationKeywordMapTryFrom(): void
    {
        $map = new RelationKeywordMap();

        $this->assertSame(RelationKind::ONE_TO_MANY, $map->tryFrom('oneToMany'));
        $this->assertNull($map->tryFrom('invalid'));
    }

    // ── RelationInverseMap ────────────────────────────────────

    public function testRelationInverseMapAll(): void
    {
        $map = new RelationInverseMap();
        $all = $map->all();

        $this->assertCount(4, $all);
    }

    public function testRelationInverseMapGetInverse(): void
    {
        $map = new RelationInverseMap();

        $this->assertSame(RelationKind::ONE_TO_MANY, $map->getInverse(RelationKind::MANY_TO_ONE));
        $this->assertSame(RelationKind::MANY_TO_ONE, $map->getInverse(RelationKind::ONE_TO_MANY));
        $this->assertSame(RelationKind::ONE_TO_ONE, $map->getInverse(RelationKind::ONE_TO_ONE));
        $this->assertSame(RelationKind::MANY_TO_MANY, $map->getInverse(RelationKind::MANY_TO_MANY));
    }

    // ── RelationKind ──────────────────────────────────────────

    public function testRelationKindValues(): void
    {
        $this->assertSame('oneToOne', RelationKind::ONE_TO_ONE->value);
        $this->assertSame('oneToMany', RelationKind::ONE_TO_MANY->value);
        $this->assertSame('manyToOne', RelationKind::MANY_TO_ONE->value);
        $this->assertSame('manyToMany', RelationKind::MANY_TO_MANY->value);
    }

    public function testRelationKindCases(): void
    {
        $this->assertCount(4, RelationKind::cases());
    }
}
