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
final readonly class PhpTypeMap
{
    /** @var array<string, string> */
    private const array MAP = [
        'string'        => 'string',
        'char'          => 'string',
        'text'          => 'string',
        'mediumText'    => 'string',
        'longText'      => 'string',
        'integer'       => 'int',
        'tinyInt'       => 'int',
        'smallInt'      => 'int',
        'bigInt'        => 'int',
        'unsignedBigInt' => 'int',
        'decimal'       => 'float',
        'float'         => 'float',
        'boolean'       => 'bool',
        'year'          => 'int',
        'date'          => '\\DateTimeImmutable',
        'time'          => '\\DateTimeImmutable',
        'datetime'      => '\\DateTimeImmutable',
        'datetimetz'    => '\\DateTimeImmutable',
        'timestamp'     => '\\DateTimeImmutable',
        'timestamptz'   => '\\DateTimeImmutable',
        'json'          => 'array',
        'simple_json'   => 'array',
        'array'         => 'array',
        'simple_array'  => 'array',
        'set'           => 'array',
        'uuid'          => 'string',
        'binary'        => 'string',
        'enum'          => 'string',
        'geometry'      => 'string',
        'point'         => 'string',
        'linestring'    => 'string',
        'polygon'       => 'string',
        'ipAddress'     => 'string',
        'macAddress'    => 'string',
    ];

    /**
     * Complete mapping table.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return self::MAP;
    }

    /**
     * Map a single FieldType to its PHP type.
     */
    public function map(FieldType $dbType): string
    {
        return self::MAP[$dbType->value] ?? $dbType->value;
    }
}
