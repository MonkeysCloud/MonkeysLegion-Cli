<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Resources\TypeScript\TypeScriptTransformer;
use MonkeysLegion\Resources\TypeScript\TypeWriter;

/**
 * Generate TypeScript declaration files from API resources and entities.
 *
 * Usage:
 *   php bin/ml types:generate
 *   php bin/ml types:generate --output=resources/js/types/generated
 */
#[CommandAttr('types:generate', 'Generate TypeScript types from resources and entities')]
final class TypesGenerateCommand extends Command
{
    protected function handle(): int
    {
        $basePath = function_exists('base_path') ? base_path() : getcwd();
        $outputDir = (string) ($this->option('output', $basePath . '/resources/js/types/generated'));

        // Find entity classes
        $entityDir = $basePath . '/app/Entity';
        $entities = $this->findClasses($entityDir, 'App\\Entity');

        // Find resource classes
        $resourceDir = $basePath . '/app/Resource';
        $resources = $this->findClasses($resourceDir, 'App\\Resource');

        if ($entities === [] && $resources === []) {
            $this->warn('No entity or resource classes found.');
            return self::SUCCESS;
        }

        $transformer = new TypeScriptTransformer();
        $writer = new TypeWriter($outputDir);

        // Write entity types
        if ($entities !== []) {
            $path = $writer->writeMany('entities', $entities, $transformer);
            $this->info("✅ Generated entity types: {$path} (" . count($entities) . " interfaces)");
        }

        // Write resource types
        if ($resources !== []) {
            $path = $writer->writeMany('resources', $resources, $transformer);
            $this->info("✅ Generated resource types: {$path} (" . count($resources) . " interfaces)");
        }

        return self::SUCCESS;
    }

    /**
     * Find PHP classes in a directory.
     *
     * @param string $dir
     * @param string $namespace
     * @return list<string>
     */
    private function findClasses(string $dir, string $namespace): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $classes = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($dir) + 1);
            $className = $namespace . '\\' . str_replace(['/', '.php'], ['\\', ''], $relative);

            if (class_exists($className)) {
                $classes[] = $className;
            }
        }

        return $classes;
    }
}
