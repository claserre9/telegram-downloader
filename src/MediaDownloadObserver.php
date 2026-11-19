<?php

declare(strict_types=1);

namespace claserre9;

/**
 * Receives the progress of a MediaDownloader run, e.g. to display it in a terminal.
 */
interface MediaDownloadObserver
{
    public function fileStarted(int $messageId, string $name, int $size): void;

    /** @param float $percent 0 to 100. */
    public function fileProgress(float $percent, float $speedMbps): void;

    public function fileDone(string $path): void;

    public function fileSkipped(int $messageId, string $name): void;

    public function fileFailed(int $messageId, string $name, string $error): void;
}
