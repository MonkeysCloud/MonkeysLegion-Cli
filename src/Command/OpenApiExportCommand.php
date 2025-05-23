<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Http\OpenApi\OpenApiGenerator;

/**
 * Export the OpenAPI JSON spec.
 *
 * Examples
 *   php vendor/bin/ml openapi:export              # prints to STDOUT
 *   php vendor/bin/ml openapi:export openapi.json # writes file
 */
final class OpenApiExportCommand extends Command
{
    /** One optional argument called {path} */
    protected string $signature   = 'openapi:export {path?}';
    protected string $description = 'Dump OpenAPI spec to stdout or a file.';

    public function __construct(private OpenApiGenerator $generator)
    {
        parent::__construct();
    }

    /**
     * @param string|null $path  If provided, spec is written to this file.
     */
    public function handle(?string $path = null): int
    {
        $json = $this->generator->toJson();

        if ($path) {
            file_put_contents($path, $json);
            $this->info("OpenAPI spec written to {$path}");
        } else {
            $this->line($json);
        }

        return 0;
    }
}