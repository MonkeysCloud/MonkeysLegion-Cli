<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * Generate a v2 controller with #[Route] attributes.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('make:controller', 'Generate a new Controller class')]
final class MakeControllerCommand extends Command
{
    use MakerHelpers;

    protected function handle(): int
    {
        $name = $this->argument(0) ?? $this->ask('Controller name (e.g., UserController):');

        if (trim($name) === '') {
            return $this->fail('Controller name is required.');
        }

        $name     = $this->ensureSuffix($this->toPascalCase($name), 'Controller');
        $resource = $this->hasOption('resource');
        $api      = $this->hasOption('api');
        $prefix   = $this->toSnakeCase($this->removeSuffix($name, 'Controller'));

        $methods = $resource || $api
            ? $this->buildResourceMethods($api)
            : $this->buildDefaultMethod();

        $stub = <<<PHP
            <?php
            declare(strict_types=1);

            namespace App\\Controller;

            use MonkeysLegion\\Router\\Attributes\\Route;
            use MonkeysLegion\\Router\\Attributes\\RoutePrefix;
            use Psr\\Http\\Message\\ResponseInterface;

            #[RoutePrefix('/{$prefix}')]
            final class {$name}
            {
            {$methods}}

            PHP;

        return $this->writeStub('app/Controller', $name, $stub);
    }

    private function buildDefaultMethod(): string
    {
        return <<<'PHP'
                #[Route('GET', '/', summary: 'Index')]
                public function index(): ResponseInterface
                {
                    // TODO: implement
                }

            PHP;
    }

    private function buildResourceMethods(bool $api): string
    {
        $methods = <<<'PHP'
                #[Route('GET', '/', summary: 'List all')]
                public function index(): ResponseInterface
                {
                    // TODO: implement
                }

                #[Route('GET', '/{id}', summary: 'Show one')]
                public function show(int $id): ResponseInterface
                {
                    // TODO: implement
                }

                #[Route('POST', '/', summary: 'Create')]
                public function store(): ResponseInterface
                {
                    // TODO: implement
                }

                #[Route('PUT', '/{id}', summary: 'Update')]
                public function update(int $id): ResponseInterface
                {
                    // TODO: implement
                }

                #[Route('DELETE', '/{id}', summary: 'Delete')]
                public function destroy(int $id): ResponseInterface
                {
                    // TODO: implement
                }

            PHP;

        if (!$api) {
            $methods .= <<<'PHP'

                    #[Route('GET', '/create', summary: 'Create form')]
                    public function create(): ResponseInterface
                    {
                        // TODO: implement
                    }

                    #[Route('GET', '/{id}/edit', summary: 'Edit form')]
                    public function edit(int $id): ResponseInterface
                    {
                        // TODO: implement
                    }

                PHP;
        }

        return $methods;
    }
}
