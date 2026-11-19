<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use claserre9\MediaPage;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

/**
 * History of $count messages (ids 1..$count); every id for which $isMedia is true is a photo,
 * every third other id is a link preview, the rest are text.
 *
 * @return array{0: Closure(int, int): array<int, array<string, mixed>>, 1: Closure(): int}
 */
$history = static function (int $count, Closure $isMedia): array {
    $calls = 0;
    $fetch = static function (int $offsetId, int $limit) use ($count, $isMedia, &$calls): array {
        $calls++;
        $top = $offsetId > 0 ? $offsetId - 1 : $count;
        $messages = [];
        for ($id = $top; $id >= 1 && count($messages) < $limit; $id--) {
            $messages[] = match (true) {
                $isMedia($id) => ['id' => $id, 'media' => ['_' => 'messageMediaPhoto']],
                $id % 3 === 0 => ['id' => $id, 'media' => ['_' => 'messageMediaWebPage']],
                default => ['id' => $id, 'message' => 'text'],
            };
        }
        // Telegram does not guarantee the order inside a batch.
        shuffle($messages);
        return $messages;
    };

    return [$fetch, static function () use (&$calls): int { return $calls; }];
};
$ids = static fn (array $page): array => array_map(static fn (array $m): int => $m['id'], $page['messages']);

// Only media is kept, across several batches, and the next page starts right below the last item.
[$fetch] = $history(1000, static fn (int $id): bool => $id % 10 === 0);
$page = MediaPage::collect($fetch, '', 0);
$assert($ids($page) === range(1000, 810, -10), 'The first page should hold the 20 newest media messages: ' . json_encode($ids($page)));
$assert($page['nextOffset'] === 810, 'The next page should start below the last listed message.');
$second = MediaPage::collect($fetch, '', $page['nextOffset']);
$assert($ids($second)[0] === 800 && count($second['messages']) === 20, 'The second page should continue without gaps or repeats.');

// End of history: no further page.
[$fetch] = $history(50, static fn (int $id): bool => $id % 10 === 0);
$page = MediaPage::collect($fetch, '', 0);
$assert($ids($page) === [50, 40, 30, 20, 10] && $page['nextOffset'] === 0, 'A short history should be listed entirely with no next page.');

// A long run of text: the scan is bounded and the page offers to continue.
[$fetch, $calls] = $history(2000, static fn (int $id): bool => $id === 3);
$page = MediaPage::collect($fetch, '', 0);
$assert($page["messages"] === [] && $page["nextOffset"] === 1501 && $calls() === MediaPage::MAX_BATCHES, 'A page should stop after a bounded number of batches.');
$found = [];
for ($pages = 1; $page['nextOffset'] > 0 && $pages < 10; $pages++) {
    $page = MediaPage::collect($fetch, '', $page['nextOffset']);
    $found = [...$found, ...$ids($page)];
}
$assert($found === [3] && $page['nextOffset'] === 0 && $pages === 4, 'Following the pages should reach the remaining media, then end.');

// The type filter is applied even if the server returns other messages.
$mixed = static fn (int $offsetId, int $limit): array => $offsetId > 0 ? [] : [
    ['id' => 4, 'media' => ['_' => 'messageMediaDocument', 'document' => ['mime_type' => 'video/mp4']]],
    ['id' => 3, 'media' => ['_' => 'messageMediaPhoto']],
    ['id' => 2, 'media' => ['_' => 'messageMediaPoll']],
    ['id' => 1, 'media' => ['_' => 'messageMediaDocument', 'document' => ['mime_type' => 'application/pdf']]],
];
$assert($ids(MediaPage::collect($mixed, 'video', 0)) === [4], 'Only videos should be kept with the video filter.');
$assert($ids(MediaPage::collect($mixed, '', 0)) === [4, 3, 1], 'Polls should never be listed.');

// A server that keeps returning the same messages must not loop.
$stuck = static fn (int $offsetId, int $limit): array => [['id' => 9, 'media' => ['_' => 'messageMediaPhoto']]];
$page = MediaPage::collect($stuck, '', 0);
$assert($ids($page) === [9] && $page['nextOffset'] === 0, 'A history that does not move back should end the listing.');

fwrite(STDOUT, "Media page tests passed.\n");
