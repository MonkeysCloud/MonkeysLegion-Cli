<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Console;

use MonkeysLegion\Cli\Console\Traits\Cli;
use MonkeysLegion\Cli\Console\Traits\HasArgumentsAndOptions;
use MonkeysLegion\Cli\Console\Traits\HasOutputHelpers;
use MonkeysLegion\Cli\Console\Traits\HasPdoHelper;
use MonkeysLegion\Cli\Console\Traits\HasProgress;
use MonkeysLegion\Cli\Console\Traits\HasSpinner;
use MonkeysLegion\Cli\Console\Traits\HasTableOutput;
use MonkeysLegion\Cli\Console\Traits\InteractsWithFiles;
use MonkeysLegion\Cli\Console\Traits\InteractsWithIO;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Base command with rich output helpers covering Laravel Artisan,
 * Symfony Console, and MonkeysLegion-exclusive features.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
abstract class Command
{
    use Cli;
    use HasArgumentsAndOptions;
    use HasOutputHelpers;
    use HasPdoHelper;
    use HasProgress;
    use HasSpinner;
    use HasTableOutput;
    use InteractsWithFiles;
    use InteractsWithIO;

    public const int SUCCESS = 0;
    public const int FAILURE = 1;

    /** Override in children. */
    abstract protected function handle(): int;

    public function __construct() {}

    // ── Runtime ───────────────────────────────────────────────────

    public function __invoke(): int
    {
        return $this->handle();
    }
}
