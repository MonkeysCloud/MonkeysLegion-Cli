<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Concerns;

/** Adds a confirm() helper to any Console\Command via `use Confirmable`; */
trait Confirmable
{
    protected function confirm(string $question = 'Continue?'): bool
    {
        fwrite(STDOUT, $question . ' [y/N] ');
        $answer = strtolower(trim(fgets(STDIN)));
        return $answer === 'y' || $answer === 'yes';
    }
}