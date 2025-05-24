<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

#[CommandAttr('make:controller', 'Generate a new Controller class stub')]
final class MakeControllerCommand extends Command
{
    use MakerHelpers;

    public function handle(): int
    {
        $input = $_SERVER['argv'][2] ?? $this->ask('Enter controller name (e.g. User)');
        $name  = preg_replace('/Controller$/', '', $input) . 'Controller';

        if (!preg_match('/^[A-Z][A-Za-z0-9]+Controller$/', $name)) {
            return $this->fail('Invalid name: must be CamelCase and end with "Controller".');
        }

        $dir  = base_path('app/Controller');
        $file = "{$dir}/{$name}.php";
        @mkdir($dir, 0755, true);

        if (file_exists($file)) {
            $this->line("ℹ️  Controller already exists: {$file}");
            return self::SUCCESS;
        }

        $stub = <<<PHP
<?php
declare(strict_types=1);

namespace App\Controller;

use MonkeysLegion\Router\Attributes\Route;
use Psr\Http\Message\ResponseInterface;

final class {$name}
{
    #[Route('GET', '/', summary: 'Index action')]
    public function index(): ResponseInterface
    {
        // TODO: implement
    }
}
PHP;

        file_put_contents($file, $stub);
        $this->info("✅  Created controller: {$file}");
        return self::SUCCESS;
    }
}