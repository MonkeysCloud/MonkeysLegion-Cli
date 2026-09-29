<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Factory;

/**
 * MonKeysLegion Framework — CLI Package
 *
 * Abstract base class for entity factories with Faker integration,
 * named states, and afterCreating callbacks.
 *
 * Usage:
 *   final class UserFactory extends ModelFactory
 *   {
 *       protected string $entityClass = User::class;
 *
 *       public function definition(): array
 *       {
 *           return [
 *               'email' => FakerProvider::email(),
 *               'name'  => FakerProvider::name(),
 *           ];
 *       }
 *
 *       public function admin(): static
 *       {
 *           return $this->state(['role' => 'admin']);
 *       }
 *   }
 *
 *   $user = (new UserFactory())->create(['email' => 'custom@test.com']);
 *   $admins = (new UserFactory())->admin()->count(5)->create();
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
abstract class ModelFactory
{
    /** @var class-string Target entity class. */
    protected string $entityClass;

    /** @var array<string, array<string, mixed>> Named state definitions. */
    private array $states = [];

    /** @var list<string> Active state names to apply. */
    private array $activeStates = [];

    /** @var list<callable(object): void> Callbacks after entity creation. */
    private array $afterCreatingCallbacks = [];

    /** @var int Number of entities to create (1 = single). */
    private int $count = 1;

    /**
     * Default attribute definition.
     *
     * @return array<string, mixed>
     */
    abstract public function definition(): array;

    /**
     * Register a named state with overriding attributes.
     *
     * @param string                $name       State name.
     * @param array<string, mixed> $attributes Attributes to override.
     */
    public function state(string $name, array $attributes): static
    {
        $this->states[$name] = $attributes;
        return $this;
    }

    /**
     * Activate a previously registered state.
     */
    public function apply(string $name): static
    {
        if (isset($this->states[$name])) {
            $this->activeStates[] = $name;
        }
        return $this;
    }

    /**
     * Register a callback to run after entity creation (before persist).
     *
     * @param callable(object): void $callback
     */
    public function afterCreating(callable $callback): static
    {
        $this->afterCreatingCallbacks[] = $callback;
        return $this;
    }

    /**
     * Set the number of entities to create.
     */
    public function count(int $count): static
    {
        $this->count = $count;
        return $this;
    }

    /**
     * Create a fresh factory instance (clears states and callbacks).
     */
    public function new(): static
    {
        return new static();
    }

    /**
     * Make entity instances without persisting.
     *
     * @param array<string, mixed> $overrides
     *
     * @return object|list<object> Single entity or list if count > 1.
     */
    public function make(array $overrides = []): object|array
    {
        $entities = [];

        for ($i = 0; $i < $this->count; $i++) {
            $attributes = $this->definition();

            // Apply active states.
            foreach ($this->activeStates as $stateName) {
                $attributes = array_merge($attributes, $this->states[$stateName]);
            }

            // Apply overrides.
            $attributes = array_merge($attributes, $overrides);

            $entity = $this->buildEntity($attributes);

            // Run afterCreating callbacks.
            foreach ($this->afterCreatingCallbacks as $callback) {
                $callback($entity);
            }

            $entities[] = $entity;
        }

        return $this->count === 1 ? $entities[0] : $entities;
    }

    /**
     * Create and persist entity instances via repository.
     *
     * @param array<string, mixed> $overrides
     *
     * @return object|list<object> Single entity or list if count > 1.
     */
    public function create(array $overrides = []): object|array
    {
        $entities = $this->make($overrides);

        if (is_array($entities)) {
            foreach ($entities as $entity) {
                $this->persist($entity);
            }
        } else {
            $this->persist($entities);
        }

        return $entities;
    }

    /**
     * Build an entity instance from attributes.
     * Override in subclasses for custom instantiation logic.
     *
     * @param array<string, mixed> $attributes
     */
    protected function buildEntity(array $attributes): object
    {
        $class    = $this->entityClass;
        $ref      = new \ReflectionClass($class);
        $entity   = $ref->newInstanceWithoutConstructor();

        foreach ($attributes as $prop => $value) {
            if (property_exists($entity, $prop)) {
                $rp = new \ReflectionProperty($class, $prop);
                if ($rp->isReadOnly() && $rp->isInitialized($entity)) {
                    continue; // Skip readonly already-set properties
                }
                $rp->setValue($entity, $value);
            }
        }

        return $entity;
    }

    /**
     * Persist an entity via its repository.
     * Override in subclasses if using a custom repository.
     */
    protected function persist(object $entity): void
    {
        // Use EntityRepository::registerRepository pattern if available.
        // Subclasses can override this to use a specific repository.
        // Default: no-op (factories may be used without a database).
    }
}
