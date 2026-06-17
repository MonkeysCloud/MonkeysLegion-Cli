<?php

declare(strict_types=1);

namespace MonkeysLegion\Cli\Console\Traits;

use MonkeysLegion\Cli\Console\Output\ProgressBar;

/**
 * Trait HasProgress
 *
 * Provides methods for displaying progress bars.
 */
trait HasProgress
{
    private ?ProgressBar $progressBar = null;

    /**
     * Start a progress bar.
     */
    protected function progressStart(int $total, string $label = ''): void
    {
        $this->progressBar = new ProgressBar($total, label: $label);
        $this->progressBar->start();
    }

    /**
     * Advance the active progress bar.
     */
    protected function progressAdvance(int $steps = 1): void
    {
        $this->progressBar?->advance($steps);
    }

    /**
     * Finish the active progress bar.
     */
    protected function progressFinish(): void
    {
        $this->progressBar?->finish();
        $this->progressBar = null;
    }
}
