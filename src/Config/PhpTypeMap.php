<?php

namespace MonkeysLegion\Cli\Config;

/**
 * Maps database types to PHP types.
 *
 * @return array<string, string>
 */
class PhpTypeMap
{

    /**
     * Returns a mapping of database types to PHP types.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return [
            'string' => 'string',
            'char' => 'string',
            'text' => 'string',
            'mediumText' => 'string',
            'longText' => 'string',
            'integer' => 'int',
            'tinyInt' => 'int',
            'smallInt' => 'int',
            'bigInt' => 'int',
            'unsignedBigInt' => 'int',
            'decimal' => 'float',
            'float' => 'float',
            'boolean' => 'bool',
            'year' => 'int',
            'date' => '\DateTimeImmutable',
            'time' => '\DateTimeImmutable',
            'datetime' => '\DateTimeImmutable',
            'datetimetz' => '\DateTimeImmutable',
            'timestamp' => '\DateTimeImmutable',
            'timestamptz' => '\DateTimeImmutable',
            'json' => 'array',
            'simple_json' => 'array',
            'array' => 'array',
            'simple_array' => 'array',
            'set' => 'array',
            'uuid' => 'string',
            'binary' => 'string',
            'enum' => 'string',
            'geometry' => 'string',
            'point' => 'string',
            'linestring' => 'string',
            'polygon' => 'string',
            'ipAddress' => 'string',
            'macAddress' => 'string',
        ];
    }

    /**
     * Maps a database type to its corresponding PHP type.
     *
     * @param string $dbType The database type to map.
     * @return string The corresponding PHP type, or 'mixed' if not found.
     */
    public function map(string $dbType): string
    {
        return $this->all()[$dbType] ?? 'mixed';
    }
}
