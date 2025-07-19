<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Console;

abstract class Command
{
    public const int SUCCESS = 0;
    public const int FAILURE = 1;

    /** Override in children */
    abstract protected function handle(): int;

    /* ---------- helpers -------------------------------------------------- */

    protected function info(string $msg): void
    {
        $this->write($msg, 32);
    }

    protected function line(string $msg): void
    {
        $this->write($msg);
    }
    
    protected function error(string $msg): void
    {
        $this->write($msg, 31);
    }

    public function __construct() {}

    private function write(string $msg, int $color = 0): void
    {
        $colorized = $color ? "\033[{$color}m{$msg}\033[0m" : $msg;
        fwrite($color === 31 ? STDERR : STDOUT, $colorized . PHP_EOL);
    }

    /* ---------- runtime entry point -------------------------------------- */

    public function __invoke(): int
    {
        return $this->handle();
    }
}
