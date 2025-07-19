<?php

namespace MonkeysLegion\Cli\Config;

/**
 * Maps relation keywords to their inverse relations.
 *
 * @return array<string, string>
 */
class RelationInverseMap
{
    
    /**
     * Returns a mapping of relation keywords to their inverse relations.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return [
            'OneToOne'   => 'OneToOne',
            'ManyToOne'  => 'OneToMany',
            'OneToMany'  => 'ManyToOne',
            'ManyToMany' => 'ManyToMany',
        ];
    }

    /**
     * Gets the inverse relation for a given owning side.
     *
     * @param string $owningSide The owning side relation keyword.
     * @return string|null The inverse relation keyword, or null if not found.
     */
    public function getInverse(string $owningSide): ?string
    {
        return $this->all()[$owningSide] ?? null;
    }
}
