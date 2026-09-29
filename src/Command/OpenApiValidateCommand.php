<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * MonKeysLegion Framework — CLI Package
 *
 * Validate the generated OpenAPI specification for common issues:
 * missing responses, duplicate operation IDs, unnamed routes, etc.
 *
 * Usage:
 *   php bin/ml openapi:validate
 *   php bin/ml openapi:validate --output=storage/openapi/openapi.json
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('openapi:validate', 'Validate the OpenAPI specification for common issues')]
final class OpenApiValidateCommand extends Command
{
    protected function handle(): int
    {
        $container = \MonkeysLegion\DI\Container::instance();

        if (!$container->has(\MonkeysLegion\OpenApi\OpenApiGenerator::class)) {
            $this->error('OpenApiGenerator not available in the container.');
            return self::FAILURE;
        }

        /** @var \MonkeysLegion\OpenApi\OpenApiGenerator $generator */
        $generator = $container->get(\MonkeysLegion\OpenApi\OpenApiGenerator::class);
        $spec = $generator->toArray();

        $errors = 0;
        $warnings = 0;

        // 1. Check for missing info
        if (empty($spec['info']['title'] ?? '')) {
            $this->error('Missing API title in info block.');
            $errors++;
        }
        if (empty($spec['info']['version'] ?? '')) {
            $this->error('Missing API version in info block.');
            $errors++;
        }

        // 2. Check for empty paths
        if (empty($spec['paths']) || (is_object($spec['paths']) && empty(get_object_vars($spec['paths'])))) {
            $this->warn('No paths defined in the OpenAPI spec.');
            $warnings++;
        }

        // 3. Check for duplicate operation IDs
        $operationIds = [];
        $duplicates = [];
        foreach (($spec['paths'] ?? []) as $path => $methods) {
            if (!is_array($methods)) {
                continue;
            }
            foreach ($methods as $method => $operation) {
                if (!is_array($operation)) {
                    continue;
                }
                $opId = $operation['operationId'] ?? '';
                if ($opId === '') {
                    $this->warn("Route {$method} {$path} has no operationId (route name).");
                    $warnings++;
                    continue;
                }
                if (isset($operationIds[$opId])) {
                    $duplicates[] = $opId;
                }
                $operationIds[$opId] = true;
            }
        }

        foreach ($duplicates as $dup) {
            $this->error("Duplicate operationId: {$dup}");
            $errors++;
        }

        // 4. Check for routes missing responses
        foreach (($spec['paths'] ?? []) as $path => $methods) {
            if (!is_array($methods)) {
                continue;
            }
            foreach ($methods as $method => $operation) {
                if (!is_array($operation)) {
                    continue;
                }
                if (!isset($operation['responses']) || empty($operation['responses'])) {
                    $this->warn("{$method} {$path} has no responses defined.");
                    $warnings++;
                }
            }
        }

        // 5. Check for missing servers
        if (empty($spec['servers'] ?? [])) {
            $this->warn('No servers defined — consumers will not know the API base URL.');
            $warnings++;
        }

        // 6. Check for deprecated routes without sunset
        foreach (($spec['paths'] ?? []) as $path => $methods) {
            if (!is_array($methods)) {
                continue;
            }
            foreach ($methods as $method => $operation) {
                if (!is_array($operation)) {
                    continue;
                }
                if (($operation['deprecated'] ?? false) && !isset($operation['description'])) {
                    $this->warn("{$method} {$path} is deprecated but has no deprecation reason.");
                    $warnings++;
                }
            }
        }

        $this->newLine();
        if ($errors > 0) {
            $this->error("❌ Validation failed: {$errors} error(s), {$warnings} warning(s).");
            return self::FAILURE;
        }

        if ($warnings > 0) {
            $this->warn("✅ Validation passed with {$warnings} warning(s).");
        } else {
            $this->info('✅ OpenAPI spec is valid — no errors or warnings.');
        }

        return self::SUCCESS;
    }
}
