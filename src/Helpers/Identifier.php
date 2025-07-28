<?php

namespace MonkeysLegion\Cli\Helpers;

class Identifier
{
    /**
     * List of reserved keywords in PHP.
     *
     * @var array<string>
     */
    protected static array $reserved = [
        '__halt_compiler',
        'abstract',
        'and',
        'array',
        'as',
        'break',
        'callable',
        'case',
        'catch',
        'class',
        'clone',
        'const',
        'continue',
        'declare',
        'default',
        'die',
        'do',
        'echo',
        'else',
        'elseif',
        'empty',
        'enddeclare',
        'endfor',
        'endforeach',
        'endif',
        'endswitch',
        'endwhile',
        'eval',
        'exit',
        'extends',
        'final',
        'for',
        'foreach',
        'function',
        'global',
        'goto',
        'if',
        'implements',
        'include',
        'include_once',
        'instanceof',
        'insteadof',
        'interface',
        'isset',
        'list',
        'namespace',
        'new',
        'or',
        'print',
        'private',
        'protected',
        'public',
        'require',
        'require_once',
        'return',
        'static',
        'switch',
        'throw',
        'trait',
        'try',
        'unset',
        'use',
        'var',
        'while',
        'xor',
        'yield',
        'match',
        'enum',
        'readonly'
    ];

    /**
     * Check if the given name is a valid identifier.
     *
     * A valid identifier starts with a letter or underscore, followed by any combination of letters, digits, or underscores.
     * It must not be a reserved keyword in PHP.
     *
     * @param string $name The identifier to check.
     * @return bool True if valid, false otherwise.
     */
    public static function isValid(string $name): bool
    {
        return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)
            && !in_array(strtolower($name), self::$reserved, true);
    }
}
