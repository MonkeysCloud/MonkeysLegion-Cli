<?php

namespace MonkeysLegion\Cli\Config;

enum RelationKind: string
{
    case ONE_TO_ONE   = 'oneToOne';
    case ONE_TO_MANY  = 'oneToMany';
    case MANY_TO_ONE  = 'manyToOne';
    case MANY_TO_MANY = 'manyToMany';
}

class RelationKeywordMap
{
    /** @var array<string, string> Map enum values to attribute names */
    private array $map = [
        RelationKind::ONE_TO_ONE->value   => 'OneToOne',
        RelationKind::ONE_TO_MANY->value  => 'OneToMany',
        RelationKind::MANY_TO_ONE->value  => 'ManyToOne',
        RelationKind::MANY_TO_MANY->value => 'ManyToMany',
    ];

    public function all(): array
    {
        return $this->map;
    }

    public function getAttribute(RelationKind $kind): ?string
    {
        return $this->map[$kind->value] ?? null;
    }

    public function tryFrom(string $keyword): ?RelationKind
    {
        return RelationKind::tryFrom($keyword);
    }
}
