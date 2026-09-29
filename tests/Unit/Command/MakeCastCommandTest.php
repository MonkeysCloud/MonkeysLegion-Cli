<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Tests\Unit\Command;

use MonkeysLegion\Cli\Command\MakeCastCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MakeCastCommandTest extends TestCase
{
    #[Test]
    public function command_has_correct_name_and_description(): void
    {
        $reflection = new \ReflectionClass(MakeCastCommand::class);
        $attrs      = $reflection->getAttributes(\MonkeysLegion\Cli\Console\Attributes\Command::class);

        self::assertCount(1, $attrs);
        $instance = $attrs[0]->newInstance();
        self::assertSame('make:cast', $instance->signature);
        self::assertSame('Generate an entity caster class', $instance->description);
    }

    #[Test]
    public function extends_command_base_class(): void
    {
        self::assertTrue(is_subclass_of(MakeCastCommand::class, \MonkeysLegion\Cli\Console\Command::class));
    }

    #[Test]
    public function uses_maker_helpers_trait(): void
    {
        $reflection = new \ReflectionClass(MakeCastCommand::class);
        $traits    = $reflection->getTraitNames();

        self::assertContains(\MonkeysLegion\Cli\Command\MakerHelpers::class, $traits);
    }
}
