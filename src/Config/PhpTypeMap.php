<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Config;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Maps DB field types to their native PHP types for code generation.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class PhpTypeMap
{
    /** @var array<string, string>|null */
    private ?array $map = null;

    /**
     * Complete mapping table.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->map ??= [
            FieldType::STRING->value          => 'string',
            FieldType::CHAR->value            => 'string',
            FieldType::TEXT->value            => 'string',
            FieldType::MEDIUM_TEXT->value     => 'string',
            FieldType::LONG_TEXT->value       => 'string',
            FieldType::INTEGER->value         => 'int',
            FieldType::TINY_INT->value        => 'int',
            FieldType::SMALL_INT->value       => 'int',
            FieldType::BIG_INT->value         => 'int',
            FieldType::UNSIGNED_BIG_INT->value => 'int',
            FieldType::DECIMAL->value         => 'float',
            FieldType::FLOAT->value           => 'float',
            FieldType::BOOLEAN->value         => 'bool',
            FieldType::YEAR->value            => 'int',
            FieldType::DATE->value            => '\\DateTimeImmutable',
            FieldType::TIME->value            => '\\DateTimeImmutable',
            FieldType::DATETIME->value        => '\\DateTimeImmutable',
            FieldType::DATETIMETZ->value      => '\\DateTimeImmutable',
            FieldType::TIMESTAMP->value       => '\\DateTimeImmutable',
            FieldType::TIMESTAMPTZ->value     => '\\DateTimeImmutable',
            FieldType::JSON->value            => 'array',
            FieldType::SIMPLE_JSON->value     => 'array',
            FieldType::ARRAY->value           => 'array',
            FieldType::SIMPLE_ARRAY->value    => 'array',
            FieldType::SET->value             => 'array',
            FieldType::UUID->value            => 'string',
            FieldType::BINARY->value          => 'string',
            FieldType::ENUM->value            => 'string',
            FieldType::GEOMETRY->value        => 'string',
            FieldType::POINT->value           => 'string',
            FieldType::LINESTRING->value      => 'string',
            FieldType::POLYGON->value         => 'string',
            FieldType::IP_ADDRESS->value      => 'string',
            FieldType::MAC_ADDRESS->value     => 'string',
        ];
    }

    /**
     * Map a single FieldType to its PHP type.
     */
    public function map(FieldType $dbType): string
    {
        return $this->all()[$dbType->value] ?? $dbType->value;
    }
}
