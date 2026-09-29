<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\DI\Container;
use MonkeysLegion\DI\ContainerDumper;

/**
 * MonKeysLegion Framework — CLI Package
 *
 * Compile the DI container for production use.
 *
 * Generates a compiled container file at storage/framework/container/
 * that eliminates runtime reflection for service resolution.
 *
 * Usage:
 *   php bin/ml container:compile
 *
 * @copyright 2026 MonKeysCloud Team
 * @license   MIT
 */
#[CommandAttr('container:compile', 'Compile the DI container for production')]
final class ContainerCompileCommand extends Command
{
    protected function handle(): int
    {
        $basePath = function_exists('base_path') ? base_path() : getcwd();
        $outputDir = $basePath . '/storage/framework/container';
        $outputFile = $outputDir . '/compiled_container.php';

        $this->info('Compiling DI container…');

        // Get the container instance
        $container = Container::instance();
        if ($container === null) {
            $this->error('❌ Container not initialized.');
            $this->comment('Run this command from within the application context.');
            return self::FAILURE;
        }

        // Ensure output directory exists
        if (!is_dir($outputDir)) {
            @mkdir($outputDir, 0755, true);
        }

        try {
            $dumper = new ContainerDumper();
            $dumper->dump($container, $outputFile);

            $definitions = $container->getDefinitions();
            $count = count($definitions);

            $this->newLine();
            $this->info('✅ Container compiled successfully!');
            $this->comment("   Output: {$outputFile}");
            $this->comment("   Definitions: {$count}");

            $size = filesize($outputFile);
            $this->comment("   File size: " . $this->formatBytes($size));

            $this->newLine();
            $this->line('  <fg=cyan>The compiled container will be used automatically</>');
            $this->line('  <fg=cyan>when the application boots in production mode.</>');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('❌ Compilation failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) return "{$bytes} B";
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }
}
