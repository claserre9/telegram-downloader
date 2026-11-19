<?php

declare(strict_types=1);

namespace claserre9\commands;

use claserre9\FileSize;
use claserre9\MediaDownloadObserver;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shows one line per file with a progress bar while it downloads.
 * Files already on disk are listed only in verbose mode (-v) and counted in the final summary.
 */
final class ConsoleMediaDownloadObserver implements MediaDownloadObserver
{
    private ?ProgressBar $progressBar = null;

    public function __construct(
        private readonly SymfonyStyle $io,
        private readonly OutputInterface $output,
    ) {
    }

    public function fileStarted(int $messageId, string $name, int $size): void
    {
        $this->io->writeln(sprintf('<info>↓</info> %s <comment>(%s, message #%d)</comment>', $name, self::formatSize($size), $messageId));
        $this->progressBar = $this->io->createProgressBar(100);
        $this->progressBar->setFormat('  [%bar%] %percent:3s%% %message%');
        $this->progressBar->setMessage('');
        $this->progressBar->start();
    }

    public function fileProgress(float $percent, float $speedMbps): void
    {
        if ($this->progressBar === null) {
            return;
        }
        $this->progressBar->setMessage($speedMbps > 0 ? sprintf('%.1f Mbit/s', $speedMbps) : '');
        $this->progressBar->setProgress((int) max(0, min(100, round($percent))));
    }

    public function fileDone(string $path): void
    {
        if ($this->progressBar !== null) {
            $this->progressBar->setProgress(100);
            $this->progressBar->finish();
            $this->progressBar = null;
            $this->io->newLine();
        }
    }

    public function fileSkipped(int $messageId, string $name): void
    {
        if ($this->output->isVerbose()) {
            $this->io->writeln(sprintf('<comment>=</comment> %s (already downloaded, message #%d)', $name, $messageId));
        }
    }

    public function fileFailed(int $messageId, string $name, string $error): void
    {
        $this->abortProgress();
        $this->io->writeln(sprintf('<error>✗ %s (message #%d): %s</error>', $name, $messageId, $error));
    }

    /** Leave the current progress bar on its own line before printing an error. */
    public function abortProgress(): void
    {
        if ($this->progressBar !== null) {
            $this->progressBar = null;
            $this->io->newLine();
        }
    }

    public static function formatSize(int $bytes): string
    {
        return $bytes <= 0 ? 'unknown size' : FileSize::format($bytes, 1);
    }
}
