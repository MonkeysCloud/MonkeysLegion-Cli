<?php

namespace MonkeysLegion\Cli\Config;

/**
 * Maps field types to their respective configurations.
 *
 * @return array<string>
 */
class FieldTypeConfig
{
    
    /**
     * Returns all field types.
     *
     * @return array<string>
     */
    public function all(): array
    {
        return [
            'string',
            'char',
            'text',
            'mediumText',
            'longText',
            'integer',
            'tinyInt',
            'smallInt',
            'bigInt',
            'unsignedBigInt',
            'decimal',
            'float',
            'boolean',
            'date',
            'time',
            'datetime',
            'datetimetz',
            'timestamp',
            'timestamptz',
            'year',
            'uuid',
            'binary',
            'json',
            'simple_json',
            'array',
            'simple_array',
            'enum',
            'set',
            'geometry',
            'point',
            'linestring',
            'polygon',
            'ipAddress',
            'macAddress',
        ];
    }
}
