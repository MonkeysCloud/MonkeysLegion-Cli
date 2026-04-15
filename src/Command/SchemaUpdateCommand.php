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
            if (!$this->confirm('Database does not exist. Create it?')) {
                $this->error('Aborted. Database creation declined.');

                return self::FAILURE;
            }

            $this->error('Please run db:create first.');

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
            return $this->applyChanges($sql, $plan);
        }

        $this->info('ℹ️  Use --dump to preview SQL, --pretend for human-readable diff, or --force to apply.');

        return self::SUCCESS;
    }

    // ── Apply changes ─────────────────────────────────────────────

    private function applyChanges(string $sql, DiffPlan $plan): int
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
                // Ignore duplicate table/column errors
                if (in_array($e->getCode(), ['42S21', '42S01', '42P07', '42701'], true)) {
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

        $this->progressFinish();
        $this->newLine();

        // Summary table
        $this->table(
            ['Metric', 'Count'],
            [
                ['Applied statements', (string) $applied],
                ['Skipped (already exist)', (string) $skipped],
                ['Total changes', (string) $plan->changeCount()],
            ],
        );

        $this->info('✅ Schema updated successfully.');

        return self::SUCCESS;
    }

    // ── Helpers ───────────────────────────────────────────────────

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
