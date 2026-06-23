<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Database\Contracts\ConnectionInterface;
use MonkeysLegion\Entity\Scanner\EntityScanner;
use MonkeysLegion\Migration\Diff\DiffPlan;
use MonkeysLegion\Migration\MigrationGenerator;

/**
 * MonkeysLegion Framework — CLI Package
 *
 * Compare entity metadata → database schema and apply changes.
 * Delegates entirely to the migration v2 package components.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
#[CommandAttr(
    'schema:update',
    'Compare entities → database and apply missing tables/columns',
    aliases: ['su'],
)]
final class SchemaUpdateCommand extends Command
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly EntityScanner $scanner,
        private readonly MigrationGenerator $generator,
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $dump    = $this->hasOption('dump');
        $force   = $this->hasOption('force');
        $pretend = $this->hasOption('pretend');

        // ── Check database ───────────────────────────────────────
        if (!$this->checkDatabaseExists()) {
            $this->error('Database does not exist. Run `php ml db:create` first.');

            return self::FAILURE;
        }

        // ── Scan entities ────────────────────────────────────────
        $this->info('🔍 Scanning entities…');

        $entityDir = function_exists('base_path') ? base_path('app/Entity') : 'app/Entity';
        $entityMetadata = $this->scanner->scanDir($entityDir);

        if ($entityMetadata === []) {
            $this->warn('No entities found in: ' . $entityDir);

            return self::SUCCESS;
        }

        $this->comment('  Found ' . count($entityMetadata) . ' entities');

        // Convert EntityMetadata → class-strings for MigrationGenerator
        $entities = array_map(
            static fn($meta) => $meta->className,
            $entityMetadata,
        );

        // ── Compute diff ─────────────────────────────────────────
        $this->info('🔍 Computing schema diff…');

        $plan = $this->generator->computeDiff($entities);

        // ── Skip tables ──────────────────────────────────────────
        // --skip-table=usage_events or --skip-table=usage_events,jobs
        $skipTables = $this->collectSkipTables();
        if ($skipTables !== []) {
            $plan->removeTables($skipTables);
            $this->comment('  Skipping tables: ' . implode(', ', $skipTables));
        }

        if ($plan->isEmpty()) {
            $this->info('✔️  Schema is already up to date.');

            return self::SUCCESS;
        }

        // ── Pretend mode — human-readable table ──────────────────
        if ($pretend) {
            $this->newLine();
            $this->alert("Schema changes detected ({$plan->changeCount()} changes)");
            $this->newLine();
            $this->line($plan->toHumanReadable());

            return self::SUCCESS;
        }

        // ── Generate SQL ─────────────────────────────────────────
        $sql = $this->generator->getRenderer()->render($plan);

        // ── Dump mode — show SQL ─────────────────────────────────
        if ($dump) {
            $this->newLine();
            $this->line("-- Generated SQL ({$plan->changeCount()} changes):");
            $this->newLine();
            $this->line($sql);

            return self::SUCCESS;
        }

        // ── Force mode — apply with backup ───────────────────────
        if ($force) {
            return $this->applyChanges($plan);
        }

        $this->info('ℹ️  Use --dump to preview SQL, --pretend for human-readable diff, or --force to apply.');

        return self::SUCCESS;
    }

    // ── Apply changes ─────────────────────────────────────────────

    private function applyChanges(DiffPlan $plan): int
    {
        // Backup current schema
        $this->info('💾 Creating schema backup…');

        try {
            $backupSql = $this->generator->backup();
            $backupDir = function_exists('base_path') ? base_path('storage/migrations') : 'storage/migrations';

            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0o755, true);
            }

            $backupFile = $backupDir . '/backup_' . date('Y_m_d_His') . '.sql';
            file_put_contents($backupFile, $backupSql);
            $this->comment("  Saved to: {$backupFile}");
        } catch (\Throwable $e) {
            $this->warn('⚠️  Backup failed: ' . $e->getMessage());
            $this->warn('  Continuing without backup…');
        }

        // Apply
        $this->info('🚀 Applying schema changes…');

        $pdo   = $this->db->pdo();
        $stmts = $this->generator->getRenderer()->renderStatements($plan);
        $total = count($stmts);

        $this->progressStart($total, 'Applying');

        $applied = 0;
        $skipped = 0;

        // Disable FK checks so column modifications don't fail
        // when other tables reference them
        $dialect = $this->generator->getDialect();
        $disableFk = $dialect->disableFkChecks();
        $enableFk  = $dialect->enableFkChecks();

        if ($disableFk !== '') {
            $pdo->exec($disableFk);
        }

        // Error codes that can be safely skipped:
        // 42S21 = Column already exists (MySQL)
        // 42S01 = Table already exists (MySQL)
        // 42P07 = Duplicate table (PostgreSQL)
        // 42701 = Duplicate column (PostgreSQL)
        // 42P16 = Invalid table definition (PostgreSQL partition key — cannot alter)
        // 42710 = Duplicate object (PostgreSQL — constraint already exists)
        $skippableErrors = ['42S21', '42S01', '42P07', '42701', '42P16', '42710'];

        try {
            foreach ($stmts as $stmt) {
                $stmt = trim($stmt);

                if ($stmt === '') {
                    $this->progressAdvance();
                    continue;
                }

                try {
                    $pdo->exec($stmt);
                    $applied++;
                } catch (\PDOException $e) {
                    if (in_array($e->getCode(), $skippableErrors, true)) {
                        $skipped++;
                    } else {
                        $this->progressFinish();
                        $this->error("❌ Failed: {$e->getMessage()}");
                        $this->comment("  Statement: " . mb_substr($stmt, 0, 80) . '…');

                        return self::FAILURE;
                    }
                }

                $this->progressAdvance();
            }
        } finally {
            // Always re-enable FK checks, even on failure
            if ($enableFk !== '') {
                $pdo->exec($enableFk);
            }
        }

        $this->progressFinish();
        $this->newLine();

        // Summary table
        $this->table(
            ['Metric', 'Count'],
            [
                ['Applied statements', (string) $applied],
                ['Skipped (already exist / partition)', (string) $skipped],
                ['Total changes', (string) $plan->changeCount()],
            ],
        );

        $this->info('✅ Schema updated successfully.');

        return self::SUCCESS;
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * Collect table names to skip from --skip-table option(s).
     *
     * Supports:
     *   --skip-table=usage_events
     *   --skip-table=usage_events,jobs
     *   --skip-table usage_events --skip-table jobs
     *
     * @return list<string>
     */
    private function collectSkipTables(): array
    {
        global $argv;

        if (!is_array($argv)) {
            return [];
        }

        $tables = [];

        for ($i = 0, $c = count($argv); $i < $c; $i++) {
            $arg = $argv[$i];

            // --skip-table=value
            if (str_starts_with($arg, '--skip-table=')) {
                $val = substr($arg, strlen('--skip-table='));
                foreach (explode(',', $val) as $t) {
                    $t = trim($t);
                    if ($t !== '') {
                        $tables[] = $t;
                    }
                }
                continue;
            }

            // --skip-table value
            if ($arg === '--skip-table' && isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
                $val = $argv[++$i];
                foreach (explode(',', $val) as $t) {
                    $t = trim($t);
                    if ($t !== '') {
                        $tables[] = $t;
                    }
                }
            }
        }

        return array_unique($tables);
    }

    private function checkDatabaseExists(): bool
    {
        try {
            $this->db->pdo();

            return true;
        } catch (\PDOException) {
            return false;
        }
    }
}
