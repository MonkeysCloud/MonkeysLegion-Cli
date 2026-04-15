<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr('make:test', 'Generate a PHPUnit test class')]
final class MakeTestCommand extends Command
{
    use MakerHelpers;

    protected function handle(): int
    {
        $name = $this->argument(0) ?? $this->ask('Test name (e.g., UserServiceTest):');

        if (trim($name) === '') {
            return $this->fail('Test name is required.');
        }

        $name = $this->ensureSuffix($this->toPascalCase($name), 'Test');
        $unit = $this->hasOption('unit');

        $ns      = $unit ? 'Tests\\Unit' : 'Tests\\Feature';
        $relDir  = $unit ? 'tests/Unit' : 'tests/Feature';
        $extends = 'TestCase';

        $stub = <<<PHP
            <?php
            declare(strict_types=1);

            namespace {$ns};

            use PHPUnit\\Framework\\TestCase;

            final class {$name} extends {$extends}
            {
                protected function setUp(): void
                {
                    parent::setUp();
                }

                public function testExample(): void
                {
                    \$this->assertTrue(true);
                }
            }

            PHP;

        return $this->writeStub($relDir, $name, $stub);
    }
}
