<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Helpers;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Validates PHP identifiers (property names, class names, etc.)
 * against syntax rules and reserved keywords.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final readonly class Identifier
{
    /** @var list<string> PHP reserved keywords (lowercase) */
    private const array RESERVED = [
        '__halt_compiler', 'abstract', 'and', 'array', 'as',
        'break', 'callable', 'case', 'catch', 'class',
        'clone', 'const', 'continue', 'declare', 'default',
        'die', 'do', 'echo', 'else', 'elseif',
        'empty', 'enddeclare', 'endfor', 'endforeach', 'endif',
        'endswitch', 'endwhile', 'eval', 'exit', 'extends',
        'final', 'for', 'foreach', 'function', 'global',
        'goto', 'if', 'implements', 'include', 'include_once',
        'instanceof', 'insteadof', 'interface', 'isset', 'list',
        'namespace', 'new', 'or', 'print', 'private',
        'protected', 'public', 'require', 'require_once', 'return',
        'static', 'switch', 'throw', 'trait', 'try',
        'unset', 'use', 'var', 'while', 'xor',
        'yield', 'match', 'enum', 'readonly',
    ];

    /**
     * Check if the given name is a valid PHP identifier.
     *
     * A valid identifier starts with a letter or underscore, followed
     * by any combination of letters, digits, or underscores.
     * It must not be a PHP reserved keyword.
     */
    public static function isValid(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)
            && !in_array(strtolower($name), self::RESERVED, true);
    }
}
