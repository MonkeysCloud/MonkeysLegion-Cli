<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Console\Traits;

use MonkeysLegion\Cli\Console\Output\TableRenderer;

/**
 * Trait HasTableOutput
 *
 * Provides a method to render tabular data.
 */
trait HasTableOutput
{
    /**
     * Render a table to STDOUT.
     *
     * @param list<string>       $headers Column headers
     * @param list<list<string>> $rows    Data rows
     * @param array<int, string> $align   Per-column alignment ('l', 'r', 'c')
     */
    protected function table(array $headers, array $rows, array $align = []): void
    {
        (new TableRenderer())->render($headers, $rows, $align);
    }
}
