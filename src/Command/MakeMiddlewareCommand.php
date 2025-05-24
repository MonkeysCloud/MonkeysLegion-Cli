<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

#[CommandAttr('make:middleware', 'Generate a new Middleware class stub')]
final class MakeMiddlewareCommand extends Command
{
    use MakerHelpers;

    public function handle(): int
    {
        $input = $_SERVER['argv'][2] ?? $this->ask('Enter middleware name (e.g. Auth)');
        $name  = preg_replace('/Middleware$/', '', $input) . 'Middleware';

        if (!preg_match('/^[A-Z][A-Za-z0-9]+Middleware$/', $name)) {
            return $this->fail('Invalid name: must be CamelCase ending with "Middleware".');
        }

        $dir  = base_path('app/Middleware');
        $file = "{$dir}/{$name}.php";
        @mkdir($dir, 0755, true);

        if (file_exists($file)) {
            $this->line("ℹ️  Middleware already exists: {$file}");
            return self::SUCCESS;
        }

        $stub = <<<PHP
<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;

final class {$name} implements MiddlewareInterface
{
    public function process(ServerRequestInterface \$request, RequestHandlerInterface \$handler): ResponseInterface
    {
        // TODO: implement middleware logic
        return \$handler->handle(\$request);
    }
}
PHP;

        file_put_contents($file, $stub);
        $this->info("✅  Created middleware: {$file}");
        return self::SUCCESS;
    }
}