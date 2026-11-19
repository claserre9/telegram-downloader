<?php

declare(strict_types=1);

namespace claserre9;

use Closure;

/**
 * One page of downloadable media for the web media list.
 *
 * Chat history mixes text messages, link previews, polls and media, so several batches
 * may be read to fill a page. Only photos and documents (videos, audio, files) are kept.
 */
final class MediaPage
{
    public const PAGE_SIZE = 20;
    public const BATCH_SIZE = 100;
    /** Bounds the work done for one page in chats with long runs of text messages. */
    public const MAX_BATCHES = 5;

    /**
     * @param Closure(int $offsetId, int $limit): array<int, array<string, mixed>> $fetch
     *        Messages older than $offsetId (0 = newest), newest first.
     * @param string $type Media type filter ('' = any downloadable media).
     * @return array{messages: list<array<string, mixed>>, nextOffset: int} nextOffset is 0 when the history is exhausted.
     */
    public static function collect(
        Closure $fetch,
        string $type,
        int $offsetId,
        int $pageSize = self::PAGE_SIZE,
        int $batchSize = self::BATCH_SIZE,
        int $maxBatches = self::MAX_BATCHES,
    ): array {
        $media = [];
        $offset = max(0, $offsetId);

        for ($batchCount = 0; $batchCount < $maxBatches; $batchCount++) {
            $batch = $fetch($offset, $batchSize);
            usort($batch, static fn (array $a, array $b): int => (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));
            if ($batch === []) {
                return ['messages' => $media, 'nextOffset' => 0];
            }

            $previousOffset = $offset;
            foreach ($batch as $message) {
                $id = (int) ($message['id'] ?? 0);
                if ($id <= 0 || ($previousOffset > 0 && $id >= $previousOffset)) {
                    continue;
                }
                $offset = $id;
                if (MediaType::matches($message, $type)) {
                    $media[] = $message;
                    if (count($media) >= $pageSize) {
                        // Older messages are read on the next page, starting below this one.
                        return ['messages' => $media, 'nextOffset' => $id > 1 ? $id : 0];
                    }
                }
            }

            if ($offset === $previousOffset || $offset <= 1) {
                // The batch did not move back in history: nothing older can be read.
                return ['messages' => $media, 'nextOffset' => 0];
            }
        }

        // Scanned enough for one request; the next page continues from here.
        return ['messages' => $media, 'nextOffset' => $offset];
    }
}
