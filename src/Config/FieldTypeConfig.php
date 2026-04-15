<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Config;

use InvalidArgumentException;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Configuration for field types used in the entity wizard.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class FieldTypeConfig
{
    /**
     * Returns all available field types.
     *
     * @return list<FieldType>
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

        throw new InvalidArgumentException("Unknown field type: {$value}");
    }
}
