<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Shared helpers for all make:* commands.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
trait MakerHelpers
{
    /**
     * Ensure a class name ends with the expected suffix.
     */
    protected function ensureSuffix(string $name, string $suffix): string
    {
        if (!str_ends_with($name, $suffix)) {
            return $name . $suffix;
        }

        return $name;
    }

    /**
     * Remove a suffix if present.
     */
    protected function removeSuffix(string $name, string $suffix): string
    {
        if (str_ends_with($name, $suffix)) {
            return substr($name, 0, -strlen($suffix));
        }

        return $name;
    }

    /**
     * Write a stub file to disk.
     *
     * @return int Exit code
     */
    protected function writeStub(string $relDir, string $className, string $content): int
    {
        $basePath = function_exists('base_path') ? base_path($relDir) : $relDir;

        if (!is_dir($basePath)) {
            mkdir($basePath, 0o755, true);
        }

        $filePath = rtrim($basePath, '/') . '/' . $className . '.php';

        if (is_file($filePath) && !$this->hasOption('force')) {
            $this->warn("File already exists: {$className}.php");

            if (!$this->confirm('Overwrite?')) {
                $this->info('Skipped.');

                return self::SUCCESS;
            }
        }

        file_put_contents($filePath, $content);

        $this->info("✅ Created: {$relDir}/{$className}.php");

        return self::SUCCESS;
    }

    /**
     * Convert to PascalCase.
     */
    protected function toPascalCase(string $name): string
    {
        return str_replace([' ', '_', '-'], '', ucwords($name, ' _-'));
    }

    /**
     * Convert to snake_case.
     */
    protected function toSnakeCase(string $name): string
    {
        $result = (string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $name);

        return strtolower($result);
    }

    /**
     * Convert to camelCase.
     */
    protected function toCamelCase(string $name): string
    {
        return lcfirst($this->toPascalCase($name));
    }

    /**
     * Shorthand fail method.
     */
    protected function fail(string $msg): int
    {
        $this->error($msg);

        return self::FAILURE;
    }
}
