<?php

namespace MonkeysLegion\Cli\Config;

/**
 * Maps relation keywords to their respective attributes.
 *
 * @return array<string, string>
 */
class RelationKeywordMap
{
    
    /**
     * Returns a mapping of relation keywords to their attributes.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return [
            'oneToOne'   => 'OneToOne',
            'oneToMany'  => 'OneToMany',
            'manyToOne'  => 'ManyToOne',
            'manyToMany' => 'ManyToMany',
        ];
    }

    /**
     * Gets the attribute for a given relation keyword.
     *
     * @param string $keyword The relation keyword.
     * @return string|null The corresponding attribute, or null if not found.
     */
    public function getAttribute(string $keyword): ?string
    {
        return $this->all()[$keyword] ?? null;
    }
}
