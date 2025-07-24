<?php

namespace MonkeysLegion\Cli\Config;

class RelationInverseMap
{
    /** @var array<RelationKind, RelationKind> */
    private array $map = [
        RelationKind::ONE_TO_ONE->value   => RelationKind::ONE_TO_ONE,
        RelationKind::MANY_TO_ONE->value  => RelationKind::ONE_TO_MANY,
        RelationKind::ONE_TO_MANY->value  => RelationKind::MANY_TO_ONE,
        RelationKind::MANY_TO_MANY->value => RelationKind::MANY_TO_MANY,
    ];

    public function all(): array
    {
        return $this->map;
    }

    public function getInverse(RelationKind $owningSide): ?RelationKind
    {
        return $this->map[$owningSide->value] ?? null;
    }
}
