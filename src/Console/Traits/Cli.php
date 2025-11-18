<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Console\Traits;

/**
 * ColoredOutput Trait
 * 
 * Provides methods for building and printing CLI output with multiple colors per line.
 * Supports text styling (bold, underline, etc.) and various color options.
 * 
 * Usage:
 * ```php
 * $this->cliLine()
 *     ->add('Error: ', 'red', 'bold')
 *     ->add('File not found ', 'white')
 *     ->add('/path/to/file', 'yellow', 'underline')
 *     ->print();
 * 
 * // Or build and get the string
 * $output = $this->cliLine()
 *     ->add('Success: ', 'green', 'bold')
 *     ->add('Task completed!', 'white')
 *     ->build();
 * ```
 */
trait Cli
{
    /**
     * Creates a new CliLineBuilder instance.
     *
     * @return CliLineBuilder
     */
    protected function cliLine(): CliLineBuilder
    {
        return new CliLineBuilder();
    }

    /**
     * Print a colored line directly with a single color.
     * 
     * @param string $text The text to print
     * @param string $color The color name
     * @param string|null $style Optional style (bold, underline, etc.)
     * @param bool $newline Whether to add a newline at the end
     */
    protected function printColored(
        string $text,
        string $color = 'white',
        ?string $style = null,
        bool $newline = true
    ): void {
        $this->cliLine()
            ->add($text, $color, $style)
            ->print($newline);
    }
}

/**
 * CliLineBuilder Class
 * 
 * Builder for constructing multi-colored CLI output lines.
 */
class CliLineBuilder
{
    /**
     * ANSI color codes mapping
     */
    private const array COLORS = [
        'black'   => 30,
        'red'     => 31,
        'green'   => 32,
        'yellow'  => 33,
        'blue'    => 34,
        'magenta' => 35,
        'cyan'    => 36,
        'white'   => 37,
        'gray'    => 90,
        'bright_red'     => 91,
        'bright_green'   => 92,
        'bright_yellow'  => 93,
        'bright_blue'    => 94,
        'bright_magenta' => 95,
        'bright_cyan'    => 96,
        'bright_white'   => 97,
    ];

    /**
     * ANSI background color codes mapping
     */
    private const array BG_COLORS = [
        'black'   => 40,
        'red'     => 41,
        'green'   => 42,
        'yellow'  => 43,
        'blue'    => 44,
        'magenta' => 45,
        'cyan'    => 46,
        'white'   => 47,
        'gray'    => 100,
    ];

    /**
     * ANSI style codes mapping
     */
    private const array STYLES = [
        'bold'          => 1,
        'dim'           => 2,
        'italic'        => 3,
        'underline'     => 4,
        'blink'         => 5,
        'reverse'       => 7,
        'hidden'        => 8,
        'strikethrough' => 9,
    ];

    /** @var array<array{text: string, codes: int[]}> */
    private array $segments = [];

    /**
     * Add a text segment with color and optional styling.
     *
     * @param string $text The text to add
     * @param string $color Color name (e.g., 'red', 'green', 'blue')
     * @param string|array|null $styles Style name or array of styles (e.g., 'bold', ['bold', 'underline'])
     * @param string|null $bgColor Optional background color
     * @return self
     */
    public function add(
        string $text,
        string $color = 'white',
        string|array|null $styles = null,
        ?string $bgColor = null
    ): self {
        $codes = [];

        // Add color code
        $colorCode = self::COLORS[$color] ?? self::COLORS['white'];
        $codes[] = $colorCode;

        // Add background color if specified
        if ($bgColor !== null && isset(self::BG_COLORS[$bgColor])) {
            $codes[] = self::BG_COLORS[$bgColor];
        }

        // Add style codes
        if ($styles !== null) {
            $styleArray = is_array($styles) ? $styles : [$styles];
            foreach ($styleArray as $style) {
                if (isset(self::STYLES[$style])) {
                    $codes[] = self::STYLES[$style];
                }
            }
        }

        $this->segments[] = [
            'text' => $text,
            'codes' => $codes,
        ];

        return $this;
    }

    /**
     * Add text without any coloring (plain text).
     *
     * @param string $text The text to add
     * @return self
     */
    public function plain(string $text): self
    {
        $this->segments[] = [
            'text' => $text,
            'codes' => [],
        ];

        return $this;
    }

    /**
     * Add a space.
     *
     * @param int $count Number of spaces to add
     * @return self
     */
    public function space(int $count = 1): self
    {
        return $this->plain(str_repeat(' ', $count));
    }

    /**
     * Add a newline character (doesn't print immediately).
     *
     * @return self
     */
    public function newline(): self
    {
        return $this->plain("\n");
    }

    /**
     * Add success text (green, bold).
     *
     * @param string $text
     * @return self
     */
    public function success(string $text): self
    {
        return $this->add($text, 'green', 'bold');
    }

    /**
     * Add error text (red, bold).
     *
     * @param string $text
     * @return self
     */
    public function error(string $text): self
    {
        return $this->add($text, 'red', 'bold');
    }

    /**
     * Add warning text (yellow, bold).
     *
     * @param string $text
     * @return self
     */
    public function warning(string $text): self
    {
        return $this->add($text, 'yellow', 'bold');
    }

    /**
     * Add info text (cyan).
     *
     * @param string $text
     * @return self
     */
    public function info(string $text): self
    {
        return $this->add($text, 'cyan');
    }

    /**
     * Add muted text (gray).
     *
     * @param string $text
     * @return self
     */
    public function muted(string $text): self
    {
        return $this->add($text, 'gray');
    }

    /**
     * Build the final colored string.
     *
     * @return string The complete ANSI-colored string
     */
    public function build(): string
    {
        $output = '';

        foreach ($this->segments as $segment) {
            if (empty($segment['codes'])) {
                $output .= $segment['text'];
            } else {
                $codes = implode(';', $segment['codes']);
                $output .= "\033[{$codes}m{$segment['text']}\033[0m";
            }
        }

        return $output;
    }

    /**
     * Print the colored line to output.
     *
     * @param bool $newline Whether to add a newline at the end
     * @param resource $stream Output stream (STDOUT or STDERR)
     */
    public function print(bool $newline = true, $stream = STDOUT): void
    {
        $output = $this->build();
        if ($newline) {
            $output .= PHP_EOL;
        }
        fwrite($stream, $output);
    }

    /**
     * Print the colored line to STDERR.
     *
     * @param bool $newline Whether to add a newline at the end
     */
    public function printError(bool $newline = true): void
    {
        $this->print($newline, STDERR);
    }

    /**
     * Get the plain text without any ANSI codes.
     *
     * @return string
     */
    public function toPlainText(): string
    {
        return implode('', array_column($this->segments, 'text'));
    }

    /**
     * Clear all segments and start over.
     *
     * @return self
     */
    public function clear(): self
    {
        $this->segments = [];
        return $this;
    }

    /**
     * Get the number of segments.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->segments);
    }

    /**
     * Check if the builder is empty.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->segments);
    }
}
