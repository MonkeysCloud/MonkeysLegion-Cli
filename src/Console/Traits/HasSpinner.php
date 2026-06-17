<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Console\Traits;

use MonkeysLegion\Cli\Console\Output\Spinner;

/**
 * Trait HasSpinner
 *
 * Provides a method to run a callback inside an animated spinner.
 */
trait HasSpinner
{
    /**
     * Run a callable with an animated spinner.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    protected function spinner(string $message, callable $callback): mixed
    {
        return (new Spinner($message))->run($callback);
    }
}
