<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Console\Traits;

/**
 * Trait HasOutputHelpers
 *
 * Provides methods for outputting formatted/colored text to STDOUT and STDERR.
 */
trait HasOutputHelpers
{
    /** Green info message. */
    protected function info(string $msg): void
    {
        $this->write($msg, 32);
    }

    /** Plain line. */
    protected function line(string $msg): void
    {
        $this->write($msg);
    }

    /** Red error to STDERR. */
    protected function error(string $msg): void
    {
        $this->write($msg, 31, STDERR);
    }

    /** Yellow warning. */
    protected function warn(string $msg): void
    {
        $this->write($msg, 33);
    }

    /** Gray italic comment. */
    protected function comment(string $msg): void
    {
        fwrite(STDOUT, "\033[3;90m{$msg}\033[0m" . PHP_EOL);
    }

    /**
     * Prominent alert box.
     *
     *   ┌──────────────────────┐
     *   │  ⚠  Alert message    │
     *   └──────────────────────┘
     */
    protected function alert(string $msg): void
    {
        $len    = mb_strlen($msg) + 6;
        $border = str_repeat('─', $len);

        fwrite(STDOUT, "\033[33m┌{$border}┐\033[0m" . PHP_EOL);
        fwrite(STDOUT, "\033[33m│\033[0m  ⚠  \033[1;33m{$msg}\033[0m  \033[33m│\033[0m" . PHP_EOL);
        fwrite(STDOUT, "\033[33m└{$border}┘\033[0m" . PHP_EOL);
    }

    /** Blank lines. */
    protected function newLine(int $count = 1): void
    {
        fwrite(STDOUT, str_repeat(PHP_EOL, $count));
    }

    /**
     * Write a colored message to a stream.
     *
     * @param resource $stream
     */
    private function write(string $msg, int $color = 0, mixed $stream = null): void
    {
        $stream    ??= STDOUT;
        $colorized   = $color > 0 ? "\033[{$color}m{$msg}\033[0m" : $msg;
        fwrite($stream, $colorized . PHP_EOL);
    }
}
