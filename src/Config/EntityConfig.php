<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Config;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Aggregate config for the entity-generation wizard.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final readonly class EntityConfig
{
    public function __construct(
        public FieldTypeConfig $fieldTypes,
        public PhpTypeMap $phpTypeMap,
        public RelationKeywordMap $relationKeywordMap,
        public RelationInverseMap $relationInverseMap,
    ) {}
}
