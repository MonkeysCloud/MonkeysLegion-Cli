<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Tests\Unit\Command;

use MonkeysLegion\Cli\Command\MakeRuleCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the make:rule command stub generation.
 *
 * We test the stub output format rather than the full command execution
 * (which requires CLI arg injection) to keep tests fast and isolated.
 */
final class MakeRuleCommandTest extends TestCase
{
    #[Test]
    public function command_has_correct_name_and_description(): void
    {
        $reflection = new \ReflectionClass(MakeRuleCommand::class);
        $attrs      = $reflection->getAttributes(\MonkeysLegion\Cli\Console\Attributes\Command::class);

        self::assertCount(1, $attrs);
        $instance = $attrs[0]->newInstance();
        self::assertSame('make:rule', $instance->signature);
        self::assertSame('Generate a validation rule class', $instance->description);
    }

    #[Test]
    public function extends_command_base_class(): void
    {
        self::assertTrue(is_subclass_of(MakeRuleCommand::class, \MonkeysLegion\Cli\Console\Command::class));
    }

    #[Test]
    public function uses_maker_helpers_trait(): void
    {
        $reflection = new \ReflectionClass(MakeRuleCommand::class);
        $traits    = $reflection->getTraitNames();

        self::assertContains(\MonkeysLegion\Cli\Command\MakerHelpers::class, $traits);
    }
}
