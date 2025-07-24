<?php

namespace MonkeysLegion\Cli\Config;

/**
 * Enum for field types.
 */
enum FieldType: string
{
    case STRING = 'string';
    case CHAR = 'char';
    case TEXT = 'text';
    case MEDIUM_TEXT = 'mediumText';
    case LONG_TEXT = 'longText';
    case INTEGER = 'integer';
    case TINY_INT = 'tinyInt';
    case SMALL_INT = 'smallInt';
    case BIG_INT = 'bigInt';
    case UNSIGNED_BIG_INT = 'unsignedBigInt';
    case DECIMAL = 'decimal';
    case FLOAT = 'float';
    case BOOLEAN = 'boolean';
    case DATE = 'date';
    case TIME = 'time';
    case DATETIME = 'datetime';
    case DATETIMETZ = 'datetimetz';
    case TIMESTAMP = 'timestamp';
    case TIMESTAMPTZ = 'timestamptz';
    case YEAR = 'year';
    case UUID = 'uuid';
    case BINARY = 'binary';
    case JSON = 'json';
    case SIMPLE_JSON = 'simple_json';
    case ARRAY = 'array';
    case SIMPLE_ARRAY = 'simple_array';
    case ENUM = 'enum';
    case SET = 'set';
    case GEOMETRY = 'geometry';
    case POINT = 'point';
    case LINESTRING = 'linestring';
    case POLYGON = 'polygon';
    case IP_ADDRESS = 'ipAddress';
    case MAC_ADDRESS = 'macAddress';
}

class FieldTypeConfig
{
    /**
     * Returns all available field types.
     * 
     * @return FieldType[]
     */
    public function all(): array
    {
        return FieldType::cases();
    }

    /**
     * Validate and parse from string.
     * 
     * @throws InvalidArgumentException
     */
    public function fromString(string $value): FieldType
    {
        foreach (FieldType::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }

        throw new \InvalidArgumentException("Unknown field type: $value");
    }
}
