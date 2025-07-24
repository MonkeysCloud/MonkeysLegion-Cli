<?php

namespace MonkeysLegion\Cli\Config;

class PhpTypeMap
{
    private ?array $map = null;

    /**
     * Returns a mapping of database types to PHP types.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        // Lazy initializition of the map to avoid unnecessary memory usage
        return $this->map ??= FieldType::cases();
    }

    /**
     * Maps a database type to its corresponding PHP type.
     *
     * @param FieldType $dbType The database type to map.
     * @return string The corresponding PHP type
     */
    public function map(FieldType $dbType): string
    {
        return $this->map[$dbType->value] ?? $dbType->value;
    }
}
