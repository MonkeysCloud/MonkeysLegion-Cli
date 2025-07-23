<?php

namespace MonkeysLegion\Cli\Config;

class PhpTypeMap
{
    private ?array $map = null;

    /**
     * Returns a mapping of FieldType cases to PHP types.
     *
     * @return array<FieldType, string>
     */
    public function all(): array
    {
        // Lazy initialization of the map to avoid unnecessary memory usage
        return $this->map ??= [
            FieldType::STRING->value => 'string',
            FieldType::CHAR->value => 'string',
            FieldType::TEXT->value => 'string',
            FieldType::MEDIUM_TEXT->value => 'string',
            FieldType::LONG_TEXT->value => 'string',
            FieldType::INTEGER->value => 'int',
            FieldType::TINY_INT->value => 'int',
            FieldType::SMALL_INT->value => 'int',
            FieldType::BIG_INT->value => 'int',
            FieldType::UNSIGNED_BIG_INT->value => 'int',
            FieldType::DECIMAL->value => 'float',
            FieldType::FLOAT->value => 'float',
            FieldType::BOOLEAN->value => 'bool',
            FieldType::YEAR->value => 'int',
            FieldType::DATE->value => '\DateTimeImmutable',
            FieldType::TIME->value => '\DateTimeImmutable',
            FieldType::DATETIME->value => '\DateTimeImmutable',
            FieldType::DATETIMETZ->value => '\DateTimeImmutable',
            FieldType::TIMESTAMP->value => '\DateTimeImmutable',
            FieldType::TIMESTAMPTZ->value => '\DateTimeImmutable',
            FieldType::JSON->value => 'array',
            FieldType::SIMPLE_JSON->value => 'array',
            FieldType::ARRAY->value => 'array',
            FieldType::SIMPLE_ARRAY->value => 'array',
            FieldType::SET->value => 'array',
            FieldType::UUID->value => 'string',
            FieldType::BINARY->value => 'string',
            FieldType::ENUM->value => 'string',
            FieldType::GEOMETRY->value => 'string',
            FieldType::POINT->value => 'string',
            FieldType::LINESTRING->value => 'string',
            FieldType::POLYGON->value => 'string',
            FieldType::IP_ADDRESS->value => 'string',
            FieldType::MAC_ADDRESS->value => 'string',
        ];
    }

    /**
     * Maps a FieldType to its corresponding PHP type.
     *
     * @param FieldType $dbType The database type to map.
     * @return string The corresponding PHP type
     */
    public function map(FieldType $dbType): string
    {
        $t = $dbType->value;
        return $this->all()[$t] ?? $t;
    }
}
