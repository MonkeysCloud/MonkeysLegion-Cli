<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Config;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Database field types supported by the entity generation wizard.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
enum FieldType: string
{
    case STRING          = 'string';
    case CHAR            = 'char';
    case TEXT            = 'text';
    case MEDIUM_TEXT     = 'mediumText';
    case LONG_TEXT       = 'longText';
    case INTEGER         = 'integer';
    case TINY_INT        = 'tinyInt';
    case SMALL_INT       = 'smallInt';
    case BIG_INT         = 'bigInt';
    case UNSIGNED_BIG_INT = 'unsignedBigInt';
    case DECIMAL         = 'decimal';
    case FLOAT           = 'float';
    case BOOLEAN         = 'boolean';
    case DATE            = 'date';
    case TIME            = 'time';
    case DATETIME        = 'datetime';
    case DATETIMETZ      = 'datetimetz';
    case TIMESTAMP       = 'timestamp';
    case TIMESTAMPTZ     = 'timestamptz';
    case YEAR            = 'year';
    case UUID            = 'uuid';
    case BINARY          = 'binary';
    case JSON            = 'json';
    case SIMPLE_JSON     = 'simple_json';
    case ARRAY           = 'array';
    case SIMPLE_ARRAY    = 'simple_array';
    case ENUM            = 'enum';
    case SET             = 'set';
    case GEOMETRY        = 'geometry';
    case POINT           = 'point';
    case LINESTRING      = 'linestring';
    case POLYGON         = 'polygon';
    case IP_ADDRESS      = 'ipAddress';
    case MAC_ADDRESS     = 'macAddress';
}
