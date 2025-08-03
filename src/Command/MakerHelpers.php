<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

// Helper trait for common ask/fail methods
trait MakerHelpers
{
    protected function ask(string $prompt): string
    {
        return function_exists('readline')
            ? trim(readline("$prompt ") ?: '')
            : trim(fgets(STDIN) ?: '');
    }

    protected function fail(string $msg): int
    {
        $this->error($msg);
        return self::FAILURE;
    }
}
