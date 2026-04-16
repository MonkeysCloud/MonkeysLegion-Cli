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
final readonly class RelationKeywordMap
{
    /** @var array<string, string> */
    private const array MAP = [
        'oneToOne'   => 'OneToOne',
        'oneToMany'  => 'OneToMany',
        'manyToOne'  => 'ManyToOne',
        'manyToMany' => 'ManyToMany',
    ];

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return self::MAP;
    }

    public function getAttribute(RelationKind $kind): ?string
    {
        return self::MAP[$kind->value] ?? null;
    }

    public function tryFrom(string $keyword): ?RelationKind
    {
        return RelationKind::tryFrom($keyword);
    }
}
