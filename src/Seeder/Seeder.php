<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Seeder;

use MonkeysLegion\Database\Contracts\ConnectionInterface;

/**
 * MonKeysLegion Framework — CLI Package
 *
 * Abstract base class for database seeders.
 *
 * The SeedCommand instantiates seeders with `new $fqcn()` and calls
 * `run($this->db)`. This base class provides that interface plus
 * convenience methods for calling dependent seeders and executing
 * raw queries.
 *
 * Usage:
 *   final class UsersSeeder extends Seeder
 *   {
 *       public function run(ConnectionInterface $db): void
 *       {
 *           $db->execute('INSERT INTO users (email, name) VALUES (?, ?)', [
 *               'admin@example.com', 'Admin User',
 *           ]);
 *       }
 *   }
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
abstract class Seeder
{
    protected ?ConnectionInterface $db = null;

    /**
     * Run the seeder. Called by SeedCommand.
     *
     * Sets the internal $db property, then delegates to seed().
     *
     * @param ConnectionInterface $db Database connection (passed by SeedCommand).
     */
    public function run(ConnectionInterface $db): void
    {
        $this->db = $db;
        $this->seed();
    }

    /**
     * Perform the seeding logic.
     * Subclasses implement this instead of run().
     */
    abstract protected function seed(): void;

    /**
     * Call another seeder class.
     *
     * @param class-string<Seeder> $seederClass
     */
    public function call(string $seederClass): void
    {
        if ($this->db === null) {
            return;
        }

        /** @var Seeder $seeder */
        $seeder = new $seederClass();
        $seeder->run($this->db);
    }

    /**
     * Execute a raw SQL query on the database connection.
     *
     * @param string              $sql
     * @param array<string,mixed> $params
     */
    protected function execute(string $sql, array $params = []): int
    {
        if ($this->db === null) {
            return 0;
        }
        return $this->db->execute($sql, $params);
    }
}
