<?php

declare(strict_types=1);

namespace claserre9;

use Closure;
use Throwable;

/**
 * Downloads the photos and files of a chat, from the newest message to the oldest.
 *
 * Files already complete on disk are skipped; partial files are resumed by MadelineProto.
 * With a DownloadState, only messages not scanned yet are fetched, and progress is saved after
 * every batch so an interrupted run resumes where it stopped.
 * Telegram access is injected as closures so the flow can be tested without a network connection.
 */
final class MediaDownloader
{
    public const BATCH_SIZE = 100;

    /** Free space left on the disk after each file, so a full download never fills the disk (1 GiB). */
    public const DISK_RESERVE = 1024 ** 3;

    /**
     * Set when the run stopped because the disk lacked space for a file.
     *
     * @var array{id: int, name: string, needed: int, available: int}|null
     */
    private ?array $diskFull = null;

    /** Matching files seen during the current run, for --limit. */
    private int $matched = 0;

    /** Whether the current run reached a message older than --since. */
    private bool $sinceReached = false;

    /** @var Closure(string): (float|false) */
    private readonly Closure $freeSpace;

    /**
     * @param Closure(int, int, int): list<array<string, mixed>> $fetchHistory Messages with minId < id < offsetId
     *        (offsetId 0 = from the newest), newest first, at most $limit: fn(offsetId, minId, limit).
     * @param Closure(array<string, mixed>): array{name: string, ext: string, size: int} $describe File name, extension and size of a message's media.
     * @param Closure(array<string, mixed>, string, Closure(float, float): void): void $download Downloads a message's media to a path, reporting progress.
     * @param string $type Media type filter (see MediaType::FILTERS), '' for every downloadable media.
     * @param int|null $maxFiles Stop after this many matching files (downloaded, skipped or failed).
     * @param int|null $since Ignore messages older than this Unix timestamp.
     * @param (Closure(string): (float|false))|null $freeSpace Free bytes on the disk of a directory (default: disk_free_space).
     * @param int $diskReserve Bytes to keep free on the disk; the run stops before a file that would use them.
     */
    public function __construct(
        private readonly Closure $fetchHistory,
        private readonly Closure $describe,
        private readonly Closure $download,
        private readonly string $directory,
        private readonly string $type = '',
        private readonly ?int $maxFiles = null,
        private readonly ?int $since = null,
        ?Closure $freeSpace = null,
        private readonly int $diskReserve = self::DISK_RESERVE,
    ) {
        $this->freeSpace = $freeSpace ?? static fn (string $directory): float|false => @disk_free_space($directory);
    }

    /**
     * @return array{messages: int, downloaded: int, skipped: int, failed: list<array{id: int, name: string, error: string}>, complete: bool, diskFull: array{id: int, name: string, needed: int, available: int}|null}
     *         "complete" is false when --limit or --since stopped the run, or failed files are left to retry.
     *         "diskFull" is set when the run stopped because the disk lacked space; that file and the older ones are left for the next run.
     * @throws Throwable If fetching the history or saving the state fails, or the user interrupts the run (Ctrl+C).
     */
    public function run(MediaDownloadObserver $observer, ?DownloadState $state = null): array
    {
        $summary = ['messages' => 0, 'downloaded' => 0, 'skipped' => 0, 'failed' => [], 'complete' => true, 'diskFull' => null];
        $this->matched = 0;
        $this->sinceReached = false;
        $this->diskFull = null;

        if ($state === null) {
            $summary['complete'] = $this->scanRange(0, null, $observer, $summary, null) === 0;
            $summary['diskFull'] = $this->diskFull;
            return $summary;
        }

        $newest = ($this->fetchHistory)(0, 0, 1);
        $newestId = is_int($newest[0]['id'] ?? null) ? $newest[0]['id'] : 0;
        $state->queueNewMessages($newestId);
        $state->save();

        $index = 0;
        while ($index < count($state->pending)) {
            [$low, $high] = $state->pending[$index];
            $checkpoint = static function (int $doneHigh) use ($state, $index): void {
                $state->pending[$index][1] = $doneHigh;
                $state->save();
            };
            $doneHigh = $this->scanRange($low, $high, $observer, $summary, $checkpoint);
            if ($doneHigh <= $low) {
                array_splice($state->pending, $index, 1);
                $state->save();
                continue;
            }
            // Range kept for the next run: it holds failed files, or --limit/--since stopped the scan.
            $summary['complete'] = false;
            if ($this->stopRequested()) {
                break;
            }
            $index++;
        }

        $summary['diskFull'] = $this->diskFull;
        return $summary;
    }

    /** Make a Telegram-supplied file name safe to use inside the download directory. */
    public static function safeFileName(string $name): string
    {
        $name = trim(str_replace(['/', '\\', "\0"], '_', $name));

        return $name === '' || $name === '.' || $name === '..' ? 'file' : $name;
    }

    /**
     * Scan messages with low < id <= high (high null = from the newest), newest first.
     *
     * @param array{messages: int, downloaded: int, skipped: int, failed: list<array{id: int, name: string, error: string}>, complete: bool, diskFull: array{id: int, name: string, needed: int, available: int}|null} $summary
     * @param (Closure(int): void)|null $checkpoint Receives, after each batch, the highest ID still to scan.
     * @return int The highest ID still to scan: equal to $low once the whole range is done.
     */
    private function scanRange(int $low, ?int $high, MediaDownloadObserver $observer, array &$summary, ?Closure $checkpoint): int
    {
        $offsetId = $high === null ? 0 : $high + 1;
        $doneHigh = $high ?? PHP_INT_MAX;
        $hasFailure = false;

        while (!$this->stopRequested()) {
            $messages = ($this->fetchHistory)($offsetId, $low, self::BATCH_SIZE);
            // Telegram may return fewer messages than requested before the end of the history,
            // so only an empty batch means there is nothing older left.
            if ($messages === []) {
                if (!$hasFailure) {
                    $doneHigh = $low;
                }
                break;
            }
            usort($messages, static fn (array $a, array $b): int => self::idOf($b) <=> self::idOf($a));

            $oldestId = $offsetId;
            foreach ($messages as $message) {
                $messageId = self::idOf($message);
                if ($this->since !== null && is_int($message['date'] ?? null) && $message['date'] < $this->since) {
                    $this->sinceReached = true;
                    break;
                }
                if ($this->maxFiles !== null && $this->matched >= $this->maxFiles) {
                    break;
                }
                if ($messageId > 0 && ($oldestId === 0 || $messageId < $oldestId)) {
                    $oldestId = $messageId;
                }

                $summary['messages']++;
                if (MediaType::matches($message, $this->type)) {
                    $this->matched++;
                    if (!$this->downloadMessage($message, $messageId, $observer, $summary)) {
                        $hasFailure = true;
                    }
                    if ($this->diskFull !== null) {
                        // Stop right away: every following file would fail the same way.
                        break;
                    }
                }
                if (!$hasFailure && $messageId > 0) {
                    // Everything from this message up is done; a failure freezes the mark so it is retried.
                    $doneHigh = min($doneHigh, $messageId - 1);
                }
            }

            if ($checkpoint !== null) {
                $checkpoint($doneHigh);
            }
            if ($oldestId === $offsetId) {
                // The batch did not move further back in history; stop instead of looping forever.
                break;
            }
            $offsetId = $oldestId;
        }

        return $doneHigh;
    }

    private function stopRequested(): bool
    {
        return $this->sinceReached
            || $this->diskFull !== null
            || ($this->maxFiles !== null && $this->matched >= $this->maxFiles);
    }

    private static function isDiskFull(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (stripos($current->getMessage(), 'No space left on device') !== false) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $message */
    private static function idOf(array $message): int
    {
        return is_int($message['id'] ?? null) ? $message['id'] : 0;
    }

    /**
     * @param array<string, mixed> $message
     * @param array{messages: int, downloaded: int, skipped: int, failed: list<array{id: int, name: string, error: string}>, complete: bool, diskFull: array{id: int, name: string, needed: int, available: int}|null} $summary
     * @return bool False if the file failed.
     */
    private function downloadMessage(array $message, int $messageId, MediaDownloadObserver $observer, array &$summary): bool
    {
        $name = '#' . $messageId;
        try {
            $info = ($this->describe)($message);
            $name = self::safeFileName($info['name'] . $info['ext']);
            $path = rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;

            clearstatcache(true, $path);
            if ($info['size'] > 0 && is_file($path) && filesize($path) === $info['size']) {
                $summary['skipped']++;
                $observer->fileSkipped($messageId, $name);
                return true;
            }

            $existing = is_file($path) ? (int) filesize($path) : 0;
            $needed = max(0, $info['size'] - $existing);
            $available = ($this->freeSpace)($this->directory);
            if ($available !== false && $needed + $this->diskReserve > $available) {
                $this->diskFull = ['id' => $messageId, 'name' => $name, 'needed' => $needed, 'available' => (int) $available];
                return false;
            }

            $observer->fileStarted($messageId, $name, $info['size']);
            ($this->download)($message, $path, static function (float $percent, float $speed) use ($observer): void {
                $observer->fileProgress($percent, $speed);
            });
            $summary['downloaded']++;
            $observer->fileDone($path);
            return true;
        } catch (Throwable $e) {
            if (TerminalQrLogin::wasInterrupted($e)) {
                throw $e;
            }
            if (self::isDiskFull($e)) {
                // The disk filled up during the download (another program, or a wrong size from Telegram).
                $available = ($this->freeSpace)($this->directory);
                $this->diskFull = ['id' => $messageId, 'name' => $name, 'needed' => 0, 'available' => $available === false ? 0 : (int) $available];
                $observer->fileFailed($messageId, $name, 'No space left on the disk');
                return false;
            }
            $summary['failed'][] = ['id' => $messageId, 'name' => $name, 'error' => $e->getMessage()];
            $observer->fileFailed($messageId, $name, $e->getMessage());
            return false;
        }
    }
}
