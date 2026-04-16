<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Console\Output;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Braille-pattern spinner animation for long-running operations.
 * Unique to MonkeysLegion — neither Laravel nor Symfony provides this.
 *
 * Usage:
 *   $spinner = new Spinner('Processing');
 *   $result  = $spinner->run(fn () => heavyOperation());
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class Spinner
{
    /** @var list<string> Braille animation frames */
    private const array FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    /**
     * @param string $message Label shown next to the spinner
     */
    public function __construct(
        private readonly string $message = 'Processing',
    ) {}

    /**
     * Run a callable with spinner animation.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        // If output is not a TTY, just run without animation
        if (!$this->isTty()) {
            fwrite(STDERR, "\033[36m{$this->message}…\033[0m" . PHP_EOL);

            return $callback();
        }

        // Hide cursor
        fwrite(STDERR, "\033[?25l");

        $frameIndex = 0;
        $this->drawFrame(self::FRAMES[$frameIndex]);

        try {
            $result = $callback();
            $this->clearLine();
            fwrite(STDERR, "\r\033[32m✓\033[0m \033[37m{$this->message}\033[0m" . PHP_EOL);

            return $result;
        } catch (\Throwable $e) {
            $this->clearLine();
            fwrite(STDERR, "\r\033[31m✗\033[0m \033[37m{$this->message}\033[0m — \033[31m{$e->getMessage()}\033[0m" . PHP_EOL);

            throw $e;
        } finally {
            // Show cursor
            fwrite(STDERR, "\033[?25h");
        }
    }

    /**
     * Manually tick the spinner (for use in loops).
     */
    public function tick(int &$frame): void
    {
        $this->drawFrame(self::FRAMES[$frame % count(self::FRAMES)]);
        $frame++;
    }

    /**
     * Show success state and clear the spinner line.
     */
    public function success(string $message = ''): void
    {
        $this->clearLine();
        $msg = $message !== '' ? $message : $this->message;
        fwrite(STDERR, "\r\033[32m✓\033[0m \033[37m{$msg}\033[0m" . PHP_EOL);
    }

    /**
     * Show error state and clear the spinner line.
     */
    public function fail(string $message = ''): void
    {
        $this->clearLine();
        $msg = $message !== '' ? $message : $this->message;
        fwrite(STDERR, "\r\033[31m✗\033[0m \033[37m{$msg}\033[0m" . PHP_EOL);
    }

    // ── Internal ──────────────────────────────────────────────────

    private function drawFrame(string $char): void
    {
        fwrite(STDERR, "\r\033[36m{$char}\033[0m \033[37m{$this->message}…\033[0m");
    }

    private function clearLine(): void
    {
        fwrite(STDERR, "\r\033[K");
    }

    private function isTty(): bool
    {
        return function_exists('posix_isatty') && posix_isatty(STDERR);
    }
}
