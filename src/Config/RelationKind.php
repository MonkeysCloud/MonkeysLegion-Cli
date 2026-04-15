<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Config;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Enum for entity relationship kinds.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
enum RelationKind: string
{
    case ONE_TO_ONE   = 'oneToOne';
    case ONE_TO_MANY  = 'oneToMany';
    case MANY_TO_ONE  = 'manyToOne';
    case MANY_TO_MANY = 'manyToMany';
}
