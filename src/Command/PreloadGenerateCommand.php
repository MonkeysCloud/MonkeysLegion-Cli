<?php
declare(strict_types=1);

namespace MonkeysLegion\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Core\Opcache\PreloadGenerator;

/**
 * MonKeysLegion Framework — CLI Package
 *
 * Generate an OPcache preload script for production use.
 *
 * Usage:
 *   php bin/ml preload:generate
 *   php bin/ml preload:generate --output=storage/preload.php
 *
 * @copyright 2026 MonKeysCloud Team
 * @license   MIT
 */
#[CommandAttr('preload:generate', 'Generate OPcache preload script for production')]
final class PreloadGenerateCommand extends Command
{
    protected function handle(): int
    {
        $basePath = function_exists('base_path') ? base_path() : getcwd();
        $output = (string) ($this->option('output', $basePath . '/bin/preload.php'));

        $this->info('Generating OPcache preload script…');

        $generator = new PreloadGenerator($basePath);
        $result = $generator->generate($output);

        $this->newLine();
        $this->info('✅ Preload script generated: ' . $result['file']);
        $this->comment("   {$result['written']} files queued for preloading");

        $this->newLine();
        $this->line('  <fg=cyan>To enable OPcache preloading, add to php.ini:</>');
        $this->newLine();
        $this->line('  <fg=white>  opcache.preload=' . $result['file'] . '</>');
        $this->line('  <fg=white>  opcache.preload_user=www-data</>');
        $this->line('  <fg=white>  opcache.enable=1</>');
        $this->line('  <fg=white>  opcache.enable_cli=1</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
