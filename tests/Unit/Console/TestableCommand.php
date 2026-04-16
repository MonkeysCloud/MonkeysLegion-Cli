<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Tests\Unit\Console;

use MonkeysLegion\Cli\Console\Command;

/**
 * Concrete command stub that exposes protected methods for testing.
 *
 * @internal
 */
final class TestableCommand extends Command
{
    protected function handle(): int
    {
        return self::SUCCESS;
    }

    public function callArgument(int $index): ?string
    {
        return $this->argument($index);
    }

    public function callOption(string $name, mixed $default = null): mixed
    {
        return $this->option($name, $default);
    }

    public function callHasOption(string $name): bool
    {
        return $this->hasOption($name);
    }

    /** @return array<string, mixed> */
    public function callAllOptions(): array
    {
        return $this->allOptions();
    }

    public function callInfo(string $msg): void
    {
        $this->info($msg);
    }

    public function callLine(string $msg): void
    {
        $this->line($msg);
    }

    public function callError(string $msg): void
    {
        $this->error($msg);
    }

    public function callWarn(string $msg): void
    {
        $this->warn($msg);
    }

    public function callComment(string $msg): void
    {
        $this->comment($msg);
    }

    public function callAlert(string $msg): void
    {
        $this->alert($msg);
    }

    public function callNewLine(int $count = 1): void
    {
        $this->newLine($count);
    }

    public function callFail(string $msg): int
    {
        $this->error($msg);

        return self::FAILURE;
    }
}
