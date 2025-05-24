<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

// Helper trait for common ask/fail methods
trait MakerHelpers
{
    private function ask(string $prompt): string
    {
        echo $prompt . ' ';
        return trim(fgets(STDIN));
    }

    private function fail(string $msg): int
    {
        $this->error($msg);
        return self::FAILURE;
    }
}