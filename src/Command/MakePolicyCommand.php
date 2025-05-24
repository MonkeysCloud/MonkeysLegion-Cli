<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

#[CommandAttr('make:policy', 'Generate a new Policy class stub')]
final class MakePolicyCommand extends Command
{
    use MakerHelpers;

    public function handle(): int
    {
        $input = $_SERVER['argv'][2] ?? $this->ask('Enter policy name (e.g. User)');
        $name  = preg_replace('/Policy$/', '', $input) . 'Policy';

        if (!preg_match('/^[A-Z][A-Za-z0-9]+Policy$/', $name)) {
            return $this->fail('Invalid name: must be CamelCase ending with "Policy".');
        }

        $dir  = base_path('app/Policy');
        $file = "{$dir}/{$name}.php";
        @mkdir($dir, 0755, true);

        if (file_exists($file)) {
            $this->line("ℹ️  Policy already exists: {$file}");
            return self::SUCCESS;
        }

        $stub = <<<PHP
<?php
declare(strict_types=1);

namespace App\Policy;

final class {$name}
{
    public function view(\$user, \$model): bool
    {
        // TODO: implement view logic
    }

    public function create(\$user): bool
    {
        // TODO: implement create logic
    }

    public function update(\$user, \$model): bool
    {
        // TODO: implement update logic
    }

    public function delete(\$user, \$model): bool
    {
        // TODO: implement delete logic
    }
}
PHP;

        file_put_contents($file, $stub);
        $this->info("✅  Created policy: {$file}");
        return self::SUCCESS;
    }
}