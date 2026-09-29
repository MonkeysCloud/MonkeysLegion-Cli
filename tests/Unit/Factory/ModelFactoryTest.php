<?php
declare(strict_types=1);

namespace Tests\Unit\Factory;

use MonkeysLegion\Cli\Factory\ModelFactory;
use MonkeysLegion\Cli\Tests\Unit\Factory\TestEntity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModelFactoryTest extends TestCase
{
    // ── Test fixture ────────────────────────────────────────────

    private function makeFactory(): ModelFactory
    {
        return new class extends ModelFactory {
            protected string $entityClass = TestEntity::class;

            public function definition(): array
            {
                return [
                    'name'  => 'Default Name',
                    'email' => 'default@test.com',
                    'age'   => 25,
                ];
            }

            public function admin(): static
            {
                return $this->state('admin', ['email' => 'admin@test.com'])->apply('admin');
            }
        };
    }

    // ── make() ──────────────────────────────────────────────────

    #[Test]
    public function make_returns_single_entity_by_default(): void
    {
        $factory = $this->makeFactory();
        $entity  = $factory->make();

        self::assertInstanceOf(TestEntity::class, $entity);
        self::assertSame('Default Name', $entity->name);
        self::assertSame('default@test.com', $entity->email);
        self::assertSame(25, $entity->age);
    }

    #[Test]
    public function make_with_overrides_applies_overrides(): void
    {
        $factory = $this->makeFactory();
        $entity  = $factory->make(['name' => 'Custom Name', 'age' => 30]);

        self::assertSame('Custom Name', $entity->name);
        self::assertSame(30, $entity->age);
        self::assertSame('default@test.com', $entity->email);
    }

    // ── count() ─────────────────────────────────────────────────

    #[Test]
    public function count_returns_array_of_entities(): void
    {
        $factory = $this->makeFactory();
        $entities = $factory->count(3)->make();

        self::assertIsArray($entities);
        self::assertCount(3, $entities);

        foreach ($entities as $entity) {
            self::assertInstanceOf(TestEntity::class, $entity);
        }
    }

    #[Test]
    public function count_with_overrides_applies_to_all(): void
    {
        $factory = $this->makeFactory();
        $entities = $factory->count(2)->make(['name' => 'Shared Name']);

        self::assertCount(2, $entities);
        self::assertSame('Shared Name', $entities[0]->name);
        self::assertSame('Shared Name', $entities[1]->name);
    }

    // ── States ──────────────────────────────────────────────────

    #[Test]
    public function state_applies_overriding_attributes(): void
    {
        $factory = $this->makeFactory();
        $entity  = $factory->admin()->make();

        self::assertSame('admin@test.com', $entity->email);
        self::assertSame('Default Name', $entity->name);
    }

    #[Test]
    public function state_overrides_take_precedence_over_overrides(): void
    {
        // Wait — overrides should take precedence over states.
        // States are applied first, then overrides.
        $factory = $this->makeFactory();
        $entity  = $factory->admin()->make(['email' => 'override@test.com']);

        self::assertSame('override@test.com', $entity->email);
    }

    // ── afterCreating callbacks ─────────────────────────────────

    #[Test]
    public function afterCreating_callback_is_called(): void
    {
        $called = false;
        $factory = $this->makeFactory();

        $factory->afterCreating(function (TestEntity $entity) use (&$called) {
            $called = true;
            $entity->name = 'Modified After Create';
        });

        $entity = $factory->make();

        self::assertTrue($called);
        self::assertSame('Modified After Create', $entity->name);
    }

    #[Test]
    public function afterCreating_called_for_each_entity_in_count(): void
    {
        $callCount = 0;
        $factory = $this->makeFactory();

        $factory->afterCreating(function () use (&$callCount) {
            $callCount++;
        });

        $factory->count(5)->make();

        self::assertSame(5, $callCount);
    }

    // ── new() ───────────────────────────────────────────────────

    #[Test]
    public function new_returns_fresh_instance_without_states(): void
    {
        $factory = $this->makeFactory();
        $factory->admin()->afterCreating(fn() => null);

        $fresh = $factory->new();
        $entity = $fresh->make();

        // Should not have admin state applied
        self::assertSame('default@test.com', $entity->email);
    }

    // ── create() ────────────────────────────────────────────────

    #[Test]
    public function create_calls_make_and_persist(): void
    {
        $factory = $this->makeFactory();
        $entity  = $factory->create();

        self::assertInstanceOf(TestEntity::class, $entity);
        self::assertSame('Default Name', $entity->name);
    }

    #[Test]
    public function create_count_returns_array(): void
    {
        $factory = $this->makeFactory();
        $entities = $factory->count(3)->create();

        self::assertIsArray($entities);
        self::assertCount(3, $entities);
    }
}
