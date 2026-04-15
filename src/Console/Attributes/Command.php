<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Console\Attributes;

use Attribute;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Marks a class as a CLI command with metadata for routing and display.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Command
{
    /**
     * @param string       $signature   Command name (e.g., 'make:entity')
     * @param string       $description Human-readable description
     * @param bool         $hidden      Hide from command list
     * @param list<string> $aliases     Alternative command names
     */
    public function __construct(
        public readonly string $signature,
        public readonly string $description = '',
        public readonly bool $hidden = false,
        public readonly array $aliases = [],
    ) {}
}