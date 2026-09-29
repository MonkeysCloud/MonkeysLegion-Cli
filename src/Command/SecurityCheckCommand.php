<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * MonKeysLegion Framework — CLI Package
 *
 * Check composer dependencies for known security vulnerabilities.
 *
 * Wraps `composer audit` with structured output and CI integration.
 * Requires Composer 2.4+.
 *
 * @copyright 2026 MonKeysCloud Team
 * @license   MIT
 */
#[CommandAttr('security:check', 'Check dependencies for known security vulnerabilities')]
final class SecurityCheckCommand extends Command
{
    protected function handle(): int
    {
        // Check if composer is available.
        $composer = $this->findComposer();
        if ($composer === null) {
            $this->error('Composer is not installed or not in PATH.');
            return self::FAILURE;
        }

        // First positional argument is the format: table (default) or json.
        $format = $this->argument(0) ?? 'table';

        if ($format === 'json') {
            return $this->runJsonAudit($composer);
        }

        return $this->runTableAudit($composer);
    }

    private function runTableAudit(string $composer): int
    {
        $output = @shell_exec($composer . ' audit --format=json 2>&1');

        if ($output === null) {
            $this->error('Failed to run composer audit.');
            return self::FAILURE;
        }

        $data = json_decode($output, true);

        if (!is_array($data)) {
            $this->error('Failed to parse composer audit output.');
            return self::FAILURE;
        }

        $advisories = $data['advisories'] ?? [];

        if ($advisories === []) {
            $this->info('✅ No known security vulnerabilities found.');
            return self::SUCCESS;
        }

        $totalVulns = 0;
        $rows       = [];

        foreach ($advisories as $package => $advisoryList) {
            if (!is_array($advisoryList)) {
                continue;
            }
            foreach ($advisoryList as $advisory) {
                $totalVulns++;
                $rows[] = [
                    $package,
                    $advisory['advisoryId'] ?? 'N/A',
                    $advisory['title'] ?? 'Unknown',
                    $advisory['severity'] ?? 'unknown',
                    $advisory['link'] ?? '',
                ];
            }
        }

        $this->error(sprintf('❌ %d security vulnerability(ies) found:', $totalVulns));
        $this->line('');

        foreach ($rows as $row) {
            [$package, $id, $title, $severity, $link] = $row;
            $sevColor = match (strtolower($severity)) {
                'critical', 'high' => 31, // red
                'medium'           => 33, // yellow
                default            => 0,
            };

            $this->line(sprintf('  Package:  %s', $package));
            $this->write(sprintf('  Severity: %s', strtoupper($severity)), $sevColor);
            $this->line(sprintf('  Title:    %s', $title));
            $this->line(sprintf('  ID:       %s', $id));
            if ($link !== '') {
                $this->line(sprintf('  Link:     %s', $link));
            }
            $this->line('');
        }

        $this->line('Run "composer audit" for more details.');
        $this->line('Run "composer update <package>" to fix vulnerabilities.');

        return self::FAILURE;
    }

    private function runJsonAudit(string $composer): int
    {
        $output = @shell_exec($composer . ' audit --format=json 2>&1');

        if ($output === null) {
            $this->line(json_encode(['status' => 'error', 'message' => 'Failed to run composer audit.']));
            return self::FAILURE;
        }

        $data = json_decode($output, true);

        if (!is_array($data)) {
            $this->line(json_encode(['status' => 'error', 'message' => 'Failed to parse output.']));
            return self::FAILURE;
        }

        $advisories = $data['advisories'] ?? [];

        $result = [
            'status'       => $advisories === [] ? 'ok' : 'vulnerable',
            'count'        => is_array($advisories) ? count($advisories) : 0,
            'advisories'   => $advisories,
        ];

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $advisories === [] ? self::SUCCESS : self::FAILURE;
    }

    private function findComposer(): ?string
    {
        // Check for composer.phar in project root.
        if (is_file('composer.phar')) {
            return 'php composer.phar';
        }

        // Check global composer.
        $which = @shell_exec('which composer 2>/dev/null');
        if (is_string($which) && trim($which) !== '') {
            return trim($which);
        }

        return null;
    }
}
