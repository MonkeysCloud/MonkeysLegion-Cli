<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Config;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Maps RelationKind enum values to their attribute class names.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class RelationKeywordMap
{
    /** @var array<string, string> */
    private array $map = [
        RelationKind::ONE_TO_ONE->value   => 'OneToOne',
        RelationKind::ONE_TO_MANY->value  => 'OneToMany',
        RelationKind::MANY_TO_ONE->value  => 'ManyToOne',
        RelationKind::MANY_TO_MANY->value => 'ManyToMany',
    ];

    /**
     * @return array<string, string>
     */
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
