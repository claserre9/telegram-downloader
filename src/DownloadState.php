<?php

declare(strict_types=1);

namespace claserre9;

use RuntimeException;

/**
 * Remembers which messages of a chat are left to scan, so a rerun only looks at new messages
 * and an interrupted run resumes where it stopped.
 *
 * Ranges are [low, high]: message IDs with low < id <= high, scanned from high down to low.
 */
final class DownloadState
{
    private const VERSION = 1;

    /**
     * @param int $knownTop Newest message ID already queued for scanning.
     * @param list<array{int, int}> $pending Ranges still to scan, in order.
     */
    private function __construct(
        private readonly string $path,
        public int $knownTop,
        public array $pending,
    ) {
    }

    /**
     * State file for a media type filter inside a download directory ('' = all media).
     */
    public static function pathFor(string $directory, string $filter): string
    {
        return rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . '.telegram-downloader' . ($filter === '' ? '' : '-' . $filter) . '.json';
    }

    /**
     * Load the state, or start from scratch if the file is missing or unreadable.
     */
    public static function load(string $path): self
    {
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!is_array($data) || ($data['version'] ?? null) !== self::VERSION || !is_int($data['known_top'] ?? null) || !is_array($data['pending'] ?? null)) {
            return new self($path, 0, []);
        }

        $pending = [];
        foreach ($data['pending'] as $range) {
            if (is_array($range) && is_int($range[0] ?? null) && is_int($range[1] ?? null) && $range[0] >= 0 && $range[1] > $range[0]) {
                $pending[] = [$range[0], $range[1]];
            }
        }

        return new self($path, max(0, $data['known_top']), $pending);
    }

    /**
     * Queue the messages newer than the last run, ahead of any range left from an interrupted run.
     */
    public function queueNewMessages(int $newestId): void
    {
        if ($newestId > $this->knownTop) {
            array_unshift($this->pending, [$this->knownTop, $newestId]);
            $this->knownTop = $newestId;
        }
    }

    /**
     * Write the state atomically, so an interruption never leaves a truncated file.
     */
    public function save(): void
    {
        $json = json_encode(['version' => self::VERSION, 'known_top' => $this->knownTop, 'pending' => $this->pending], JSON_PRETTY_PRINT);
        $temporary = $this->path . '.tmp';
        if ($json === false || file_put_contents($temporary, $json . "\n") === false || !rename($temporary, $this->path)) {
            throw new RuntimeException('Cannot save the download progress to ' . $this->path);
        }
    }
}
