<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Factory;

/**
 * MonKeysLegion Framework — CLI Package
 *
 * Provides fake data generation for factories.
 * Uses FakerPHP when available, falls back to simple generators.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class FakerProvider
{
    /** @var \Faker\Generator|null */
    private static ?object $faker = null;

    /**
     * Get the Faker generator, or null if Faker is not installed.
     */
    public static function faker(): ?object
    {
        if (self::$faker !== null) {
            return self::$faker;
        }

        if (class_exists(\Faker\Generator::class)) {
            self::$faker = \Faker\Factory::create();
            return self::$faker;
        }

        return null;
    }

    /**
     * Set a custom Faker instance (for testing).
     */
    public static function setFaker(object $faker): void
    {
        self::$faker = $faker;
    }

    // ── Fallback generators (used when Faker is not available) ──

    /**
     * Generate a random string of given length.
     */
    public static function randomString(int $length = 10): string
    {
        return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
    }

    /**
     * Generate a random email address.
     */
    public static function email(): string
    {
        $faker = self::faker();
        if ($faker !== null) {
            return $faker->email();
        }
        return self::randomString(8) . '@example.com';
    }

    /**
     * Generate a random name.
     */
    public static function name(): string
    {
        $faker = self::faker();
        if ($faker !== null) {
            return $faker->name();
        }
        $firstNames = ['Alice', 'Bob', 'Charlie', 'Diana', 'Eve', 'Frank', 'Grace', 'Henry'];
        $lastNames  = ['Smith', 'Jones', 'Brown', 'Davis', 'Wilson', 'Taylor', 'Clark', 'Lee'];
        return $firstNames[array_rand($firstNames)] . ' ' . $lastNames[array_rand($lastNames)];
    }

    /**
     * Generate a random integer between min and max.
     */
    public static function intBetween(int $min, int $max): int
    {
        $faker = self::faker();
        if ($faker !== null) {
            return $faker->numberBetween($min, $max);
        }
        return random_int($min, $max);
    }

    /**
     * Generate a random float between min and max with given decimals.
     */
    public static function float(float $min, float $max, int $decimals = 2): float
    {
        $factor = 10 ** $decimals;
        return round(random_int((int)($min * $factor), (int)($max * $factor)) / $factor, $decimals);
    }

    /**
     * Generate a random boolean.
     */
    public static function boolean(): bool
    {
        return (bool) random_int(0, 1);
    }

    /**
     * Pick a random element from an array.
     */
    public static function randomElement(array $elements): mixed
    {
        return $elements[array_rand($elements)];
    }

    /**
     * Generate a random date string (Y-m-d H:i:s).
     */
    public static function datetime(): string
    {
        $faker = self::faker();
        if ($faker !== null) {
            return $faker->dateTime()->format('Y-m-d H:i:s');
        }
        return date('Y-m-d H:i:s', random_int(time() - 86400 * 365, time()));
    }

    /**
     * Generate a random paragraph of text.
     */
    public static function paragraph(int $sentences = 3): string
    {
        $faker = self::faker();
        if ($faker !== null) {
            return $faker->paragraph($sentences);
        }
        $words = ['lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur', 'adipiscing', 'elit', 'sed', 'do', 'eiusmod', 'tempor', 'incididunt'];
        $result = [];
        for ($s = 0; $s < $sentences; $s++) {
            $sentenceWords = [];
            for ($w = 0; $w < 8; $w++) {
                $sentenceWords[] = $words[array_rand($words)];
            }
            $result[] = ucfirst(implode(' ', $sentenceWords)) . '.';
        }
        return implode(' ', $result);
    }
}
