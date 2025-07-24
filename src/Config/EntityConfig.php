<?php

namespace MonkeysLegion\Cli\Config;

/**
 * Maps entity configuration keys to their respective classes.
 *
 * @return array<string, string>
 */
class EntityConfig
{
    
    public function __construct(
        public readonly FieldTypeConfig $fieldTypes,
        public readonly PhpTypeMap $phpTypeMap,
        public readonly RelationKeywordMap $relationKeywordMap,
        public readonly RelationInverseMap $relationInverseMap,
    ) {}
}
