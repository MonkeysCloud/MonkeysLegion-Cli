<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Presets;

/**
 * MonKeysLegion Framework — CLI Package
 *
 * Interface for frontend starter presets.
 *
 * Presets install frontend dependencies, configuration files,
 * and starter templates for a specific stack (React, Vue, etc.).
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
interface PresetInterface
{
    /**
     * Get the preset name (e.g., 'react', 'vue').
     */
    public function name(): string;

    /**
     * Get the description shown in the CLI.
     */
    public function description(): string;

    /**
     * Install the preset into the project.
     *
     * @param string $basePath The project root path.
     * @param callable(string): void $output Output callback for CLI messages.
     */
    public function install(string $basePath, callable $output): void;

    /**
     * Get the npm packages to install.
     *
     * @return array<string, string> package => version
     */
    public function packages(): array;

    /**
     * Get the dev dependencies to install.
     *
     * @return array<string, string> package => version
     */
    public function devPackages(): array;
}
