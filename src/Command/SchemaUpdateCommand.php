<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Database\MySQL\Connection;
use MonkeysLegion\Entity\Scanner\EntityScanner;
use MonkeysLegion\Migration\MigrationGenerator;
use ReflectionException;

#[CommandAttr(
    'schema:update',
    'Compare entities → database and apply missing tables/columns (use --dump or --force)'
)]
final class SchemaUpdateCommand extends Command
{
    public function __construct(
        private Connection $db,
        private EntityScanner $scanner,
        private MigrationGenerator $generator
    ) {
        parent::__construct();
    }

    /**
     * Handle the command.
     *
     * @return int
     * @throws ReflectionException
     */
    public function handle(): int
    {
        $args  = $_SERVER['argv'];
        $dump  = in_array('--dump', $args, true);
        $force = in_array('--force', $args, true);

        // 1) Scan your Entity classes directory
        $this->line('🔍 Scanning entities…');
        $entities = $this->scanner->scanDir(base_path('app/Entity')); // ← use scanDir()

        // 2) Read current DB schema
        $this->line('🔍 Reading current database schema…');
        $schema = $this->introspectSchema();

        // 3) Compute diff
        $sql = trim($this->generator->diff($entities, $schema));
        if ($sql === '') {
            $this->info('✔️  Schema is already up to date.');
            return self::SUCCESS;
        }

        // 4) Dump if requested
        if ($dump) {
            $this->line("\n-- Generated SQL:\n" . $sql . "\n");
        }

        // 5) Apply if forced
        if ($force) {
            $this->db->pdo()->exec($sql);
            $this->info('✅  Schema updated successfully.');
        } elseif (! $dump) {
            $this->info('ℹ️  No action taken. Use `--dump` to preview or `--force` to apply.');
        }

        return self::SUCCESS;
    }

    /**
     * Introspect the MySQL schema into an array:
     *  [ tableName => [ columnName => columnInfoArray, … ], … ]
     */
    private function introspectSchema(): array
    {
        $pdo    = $this->db->pdo();
        $tables = $pdo
            ->query("SHOW TABLES")
            ->fetchAll(\PDO::FETCH_COLUMN);

        $schema = [];
        foreach ($tables as $table) {
            $cols = $pdo
                ->query("SHOW COLUMNS FROM `{$table}`")
                ->fetchAll(\PDO::FETCH_ASSOC);
            $schema[$table] = [];
            foreach ($cols as $col) {
                $schema[$table][$col['Field']] = $col;
            }
        }
        return $schema;
    }
}