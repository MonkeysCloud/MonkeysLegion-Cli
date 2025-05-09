<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Console\Attributes;

use Attribute;

/**
 * #[Command('name', 'description')]
 * Marks a class as a CLI command and provides its signature.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Command
{
    public function __construct(
        public string $signature,
        public string $description = ''
    ) {}
}