<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Config;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Maps each RelationKind to its inverse.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final readonly class RelationInverseMap
{
    /** @var array<string, RelationKind> */
    private const array MAP = [
        'oneToOne'   => RelationKind::ONE_TO_ONE,
        'manyToOne'  => RelationKind::ONE_TO_MANY,
        'oneToMany'  => RelationKind::MANY_TO_ONE,
        'manyToMany' => RelationKind::MANY_TO_MANY,
    ];

    /**
     * @return array<string, RelationKind>
     */
    public function all(): array
    {
        return self::MAP;
    }

    public function getInverse(RelationKind $owningSide): ?RelationKind
    {
        return self::MAP[$owningSide->value] ?? null;
    }
}
