<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Console\Traits;

use PDO;
use PDOStatement;

/**
 * Trait HasPdoHelper
 *
 * Provides database PDO helper queries execution safely.
 */
trait HasPdoHelper
{
    /**
     * Execute a query with error handling.
     */
    protected function safeQuery(PDO $pdo, string $sql): PDOStatement
    {
        $stmt = $pdo->query($sql);

        if (!$stmt) {
            $error = is_string($pdo->errorInfo()[2] ?? null) ? $pdo->errorInfo()[2] : 'Unknown error';
            throw new \RuntimeException('Query failed: ' . $error);
        }

        return $stmt;
    }
}
