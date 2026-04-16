<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Http\OpenApi\OpenApiGenerator;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Export the OpenAPI JSON specification.
 *
 * Usage:
 *   php ml openapi:export              # print to STDOUT
 *   php ml openapi:export openapi.json # write to file
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('openapi:export', 'Dump OpenAPI spec to stdout or a file')]
final class OpenApiExportCommand extends Command
{
    public function __construct(
        private readonly OpenApiGenerator $generator,
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $json = $this->generator->toJson();
        $path = $this->argument(0);

        if (is_string($path) && $path !== '') {
            file_put_contents($path, $json);
            $this->info("✅ OpenAPI spec written to: {$path}");
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }
}