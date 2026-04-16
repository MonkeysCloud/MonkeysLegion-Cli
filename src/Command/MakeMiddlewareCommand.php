<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Generate a PSR-15 middleware class.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('make:middleware', 'Generate a PSR-15 middleware class')]
final class MakeMiddlewareCommand extends Command
{
    use MakerHelpers;

    protected function handle(): int
    {
        $input = $this->argument(0) ?? $this->ask('Middleware name (e.g., Auth):');
        $name  = $this->ensureSuffix($this->toPascalCase($input), 'Middleware');

        if (!preg_match('/^[A-Z][A-Za-z0-9]+Middleware$/', $name)) {
            $this->error('Invalid name — must be PascalCase ending with "Middleware".');

            return self::FAILURE;
        }

        $dir  = function_exists('base_path') ? base_path('app/Middleware') : 'app/Middleware';
        $file = "{$dir}/{$name}.php";

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        if (is_file($file)) {
            $this->comment("Middleware already exists: {$file}");

            return self::SUCCESS;
        }

        $stub = <<<PHP
            <?php
            declare(strict_types=1);

            namespace App\\Middleware;

            use Psr\\Http\\Server\\MiddlewareInterface;
            use Psr\\Http\\Message\\ServerRequestInterface;
            use Psr\\Http\\Server\\RequestHandlerInterface;
            use Psr\\Http\\Message\\ResponseInterface;

            final class {$name} implements MiddlewareInterface
            {
                public function process(
                    ServerRequestInterface \$request,
                    RequestHandlerInterface \$handler,
                ): ResponseInterface {
                    // TODO: implement middleware logic

                    return \$handler->handle(\$request);
                }
            }
            PHP;

        file_put_contents($file, $stub);
        $this->info("✅ Created middleware: {$file}");

        return self::SUCCESS;
    }
}
