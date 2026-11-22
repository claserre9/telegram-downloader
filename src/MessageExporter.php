<?php

declare(strict_types=1);

namespace claserre9;

use Closure;
use RuntimeException;

/**
 * Exports the messages of a chat to messages.jsonl, one JSON record per line (see MessageJson).
 *
 * Like the media download, the history is read from the newest message to the oldest and progress is saved after
 * every batch: a rerun only exports messages posted since, and an interrupted run resumes where it stopped without
 * duplicating lines. Lines are therefore in export order; sort them by "id" for the chat's order.
 */
final class MessageExporter
{
    public const BATCH_SIZE = 100;
    public const FILE = 'messages.jsonl';
    public const JSON_FILE = 'messages.json';

    /** @var array<int, array{id: int, name: string, username?: string}> Senders and chats seen so far. */
    private array $peers = [];

    private int $exported = 0;
    private bool $sinceReached = false;
    private bool $mediaStopped = false;

    /** @var resource|null */
    private $file = null;

    /**
     * @param Closure(int, int, int): array<string, mixed> $fetchHistory messages.getHistory result ("messages", "users",
     *        "chats") for messages with minId < id < offsetId (offsetId 0 = from the newest), newest first:
     *        fn(offsetId, minId, limit).
     * @param int|null $maxMessages Stop after exporting this many messages.
     * @param int|null $since Ignore messages older than this Unix timestamp.
     * @param (Closure(array<string, mixed>): bool)|null $onMedia Called before writing a message that has a photo or
     *        file, e.g. to download it; returning false stops the export before that message (disk full).
     * @param (Closure(int): void)|null $progress Receives the number of messages exported so far, after each batch.
     */
    public function __construct(
        private readonly Closure $fetchHistory,
        private readonly MessageJson $format,
        private readonly string $directory,
        private readonly ?int $maxMessages = null,
        private readonly ?int $since = null,
        private readonly ?Closure $onMedia = null,
        private readonly ?Closure $progress = null,
    ) {
    }

    public static function pathIn(string $directory): string
    {
        return rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::FILE;
    }

    /**
     * @return array{exported: int, complete: bool, mediaStopped: bool, restarted: bool}
     *         "complete" is false when --limit, --since or $onMedia stopped the run before the oldest message.
     *         "restarted" is true when messages.jsonl was missing or shorter than saved, so the whole history was
     *         exported again.
     * @throws \Throwable If fetching the history or writing the files fails, or the user interrupts the run (Ctrl+C).
     */
    public function run(DownloadState $state): array
    {
        $this->exported = 0;
        $this->sinceReached = false;
        $this->mediaStopped = false;
        $restarted = $this->open($state);
        $summary = ['exported' => 0, 'complete' => true, 'mediaStopped' => false, 'restarted' => $restarted];

        try {
            $newest = ($this->fetchHistory)(0, 0, 1);
            $messages = is_array($newest['messages'] ?? null) ? $newest['messages'] : [];
            $newestId = is_array($messages[0] ?? null) && is_int($messages[0]['id'] ?? null) ? $messages[0]['id'] : 0;
            $state->queueNewMessages($newestId);
            $this->save($state);

            $index = 0;
            while ($index < count($state->pending)) {
                [$low, $high] = $state->pending[$index];
                $checkpoint = function (int $doneHigh) use ($state, $index): void {
                    $state->pending[$index][1] = $doneHigh;
                    $this->save($state);
                };
                $doneHigh = $this->scanRange($low, $high, $checkpoint);
                if ($doneHigh <= $low) {
                    array_splice($state->pending, $index, 1);
                    $this->save($state);
                    continue;
                }
                $summary['complete'] = false;
                if ($this->stopRequested()) {
                    break;
                }
                $index++;
            }
        } finally {
            if ($this->file !== null) {
                fclose($this->file);
                $this->file = null;
            }
        }

        $summary['exported'] = $this->exported;
        $summary['mediaStopped'] = $this->mediaStopped;
        return $summary;
    }

    /**
     * Write messages.json: every record of messages.jsonl in one array, oldest first, one record per message ID.
     *
     * @return int Number of messages written.
     */
    public static function writeJson(string $directory): int
    {
        $source = self::pathIn($directory);
        $input = @fopen($source, 'rb');
        if ($input === false) {
            throw new RuntimeException("Cannot read $source");
        }
        $lines = [];
        while (($line = fgets($input)) !== false) {
            // Records start with their ID, so the file can be sorted without decoding every message.
            if (preg_match('/\A\{"id":(\d+)[,}]/', $line, $matches) === 1) {
                $lines[(int) $matches[1]] = rtrim($line, "\r\n");
            }
        }
        fclose($input);
        ksort($lines);

        $target = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::JSON_FILE;
        $temporary = $target . '.tmp';
        $json = $lines === [] ? "[]\n" : "[\n" . implode(",\n", $lines) . "\n]\n";
        if (file_put_contents($temporary, $json) === false || !rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException("Cannot write $target");
        }

        return count($lines);
    }

    /**
     * Open messages.jsonl for appending, dropping lines written after the last saved state.
     *
     * @return bool Whether the export starts over because the file no longer matches the state.
     */
    private function open(DownloadState $state): bool
    {
        $path = self::pathIn($this->directory);
        clearstatcache(true, $path);
        $size = is_file($path) ? (int) filesize($path) : null;

        $restarted = false;
        if (!$state->isNew() && ($size === null || $state->outputBytes === null || $size < $state->outputBytes)) {
            // The file was deleted or shortened: the state no longer says which messages it holds.
            $state->knownTop = 0;
            $state->pending = [];
            $restarted = true;
        }

        $file = @fopen($path, 'cb');
        if ($file === false) {
            throw new RuntimeException("Cannot write $path");
        }
        $keep = $state->isNew() ? 0 : max(0, (int) $state->outputBytes);
        if (!ftruncate($file, $keep) || fseek($file, $keep) !== 0) {
            fclose($file);
            throw new RuntimeException("Cannot write $path");
        }
        $this->file = $file;
        $state->outputBytes = $keep;

        return $restarted;
    }

    private function save(DownloadState $state): void
    {
        if ($this->file !== null) {
            fflush($this->file);
            $position = ftell($this->file);
            $state->outputBytes = $position === false ? $state->outputBytes : $position;
        }
        $state->save();
    }

    /**
     * Export messages with low < id <= high (high null = from the newest), newest first.
     *
     * @param Closure(int): void $checkpoint Receives the highest ID still to export, after each batch and media file.
     * @return int The highest ID still to export: equal to $low once the whole range is done.
     */
    private function scanRange(int $low, ?int $high, Closure $checkpoint): int
    {
        $offsetId = $high === null ? 0 : $high + 1;
        $doneHigh = $high ?? PHP_INT_MAX;

        while (!$this->stopRequested()) {
            $history = ($this->fetchHistory)($offsetId, $low, self::BATCH_SIZE);
            $messages = array_values(array_filter(is_array($history['messages'] ?? null) ? $history['messages'] : [], 'is_array'));
            if ($messages === []) {
                $doneHigh = $low;
                break;
            }
            $this->peers = MessageJson::peersFrom($history) + $this->peers;
            usort($messages, static fn (array $a, array $b): int => self::idOf($b) <=> self::idOf($a));

            $oldestId = $offsetId;
            foreach ($messages as $message) {
                $messageId = self::idOf($message);
                if ($this->since !== null && is_int($message['date'] ?? null) && $message['date'] < $this->since) {
                    $this->sinceReached = true;
                    break;
                }
                if ($this->maxMessages !== null && $this->exported >= $this->maxMessages) {
                    break;
                }
                if ($messageId > 0 && ($oldestId === 0 || $messageId < $oldestId)) {
                    $oldestId = $messageId;
                }

                $withMedia = $this->onMedia !== null && MediaType::matches($message, '');
                if ($withMedia && !($this->onMedia)($message)) {
                    // Left for the next run, which starts again with this message.
                    $this->mediaStopped = true;
                    break;
                }

                $record = $this->format->convert($message, $this->peers);
                if ($record !== null) {
                    $this->write(MessageJson::line($record));
                    $this->exported++;
                }
                if ($messageId > 0) {
                    $doneHigh = min($doneHigh, $messageId - 1);
                }
                if ($withMedia) {
                    // A file can take a while: save progress so an interruption does not repeat it.
                    $checkpoint($doneHigh);
                }
            }

            $checkpoint($doneHigh);
            if ($this->progress !== null) {
                ($this->progress)($this->exported);
            }
            if ($oldestId === $offsetId) {
                // The batch did not move further back in history; stop instead of looping forever.
                break;
            }
            $offsetId = $oldestId;
        }

        return $doneHigh;
    }

    private function write(string $line): void
    {
        if ($this->file === null || fwrite($this->file, $line) !== strlen($line)) {
            throw new RuntimeException('Cannot write to ' . self::pathIn($this->directory) . ' (is the disk full?)');
        }
    }

    private function stopRequested(): bool
    {
        return $this->sinceReached
            || $this->mediaStopped
            || ($this->maxMessages !== null && $this->exported >= $this->maxMessages);
    }

    /** @param array<string, mixed> $message */
    private static function idOf(array $message): int
    {
        return is_int($message['id'] ?? null) ? $message['id'] : 0;
    }
}
