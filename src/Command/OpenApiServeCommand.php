<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * MonKeysLegion Framework — CLI Package
 *
 * Serve the OpenAPI specification and Swagger UI via PHP built-in server.
 *
 * Usage:
 *   php bin/ml openapi:serve
 *   php bin/ml openapi:serve --port=8888
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('openapi:serve', 'Serve OpenAPI spec and Swagger UI via PHP built-in server')]
final class OpenApiServeCommand extends Command
{
    protected function handle(): int
    {
        $port = (int) ($this->option('port', '8888'));
        $host = (string) ($this->option('host', '127.0.0.1'));
        $basePath = function_exists('base_path') ? base_path() : getcwd();

        // Write the spec to a temporary file for serving
        $container = \MonkeysLegion\DI\Container::instance();

        if (!$container->has(\MonkeysLegion\OpenApi\OpenApiGenerator::class)) {
            $this->error('OpenApiGenerator not available in the container.');
            return self::FAILURE;
        }

        /** @var \MonkeysLegion\OpenApi\OpenApiGenerator $generator */
        $generator = $container->get(\MonkeysLegion\OpenApi\OpenApiGenerator::class);
        $json = $generator->toJson();

        $docRoot = $basePath . '/storage/openapi';
        if (!is_dir($docRoot)) {
            @mkdir($docRoot, 0755, true);
        }

        file_put_contents($docRoot . '/openapi.json', $json);

        // Write Swagger UI HTML
        $swaggerHtml = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>MonKeysLegion API — Swagger UI</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script>
        window.onload = function() {
            window.ui = SwaggerUIBundle({
                url: '/openapi.json',
                dom_id: '#swagger-ui',
                deepLinking: true,
            });
        };
    </script>
</body>
</html>
HTML;

        file_put_contents($docRoot . '/index.html', $swaggerHtml);

        $this->info("Starting OpenAPI server at http://{$host}:{$port}");
        $this->comment("Swagger UI:  http://{$host}:{$port}/");
        $this->comment("Spec JSON:    http://{$host}:{$port}/openapi.json");
        $this->newLine();
        $this->line("Press Ctrl+C to stop.");

        // Start PHP built-in server
        $router = $docRoot . '/_router.php';
        file_put_contents($router, '<?php
        $uri = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
        if ($uri === "/") { readfile(__DIR__ . "/index.html"); return true; }
        if ($uri === "/openapi.json") {
            header("Content-Type: application/json");
            readfile(__DIR__ . "/openapi.json");
            return true;
        }
        return false;
        ');

        $cmd = sprintf('php -S %s:%d -t %s %s', $host, $port, escapeshellarg($docRoot), escapeshellarg($router));

        // Run in foreground
        @passthru($cmd, $result);

        return $result === 0 ? self::SUCCESS : self::FAILURE;
    }
}
