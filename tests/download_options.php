<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use claserre9\commands\DownloadOptions;
use claserre9\FileSize;
use claserre9\MediaType;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$parse = static fn (array $overrides = []): DownloadOptions => DownloadOptions::parse(
    ...array_merge([
        'chat' => null,
        'envChat' => '@env_channel',
        'type' => null,
        'output' => null,
        'limit' => null,
        'since' => null,
        'rescan' => false,
        'projectRoot' => '/project',
        'workingDirectory' => '/home/user/work',
    ], $overrides)
);

$rejects = static function (array $overrides, string $expectedText) use ($parse, $assert): void {
    try {
        $parse($overrides);
    } catch (InvalidArgumentException $e) {
        $assert(str_contains($e->getMessage(), $expectedText), 'Unexpected message: ' . $e->getMessage());
        return;
    }
    $assert(false, 'Options ' . json_encode($overrides) . ' should be rejected.');
};

// Chat: the argument wins over TELEGRAM_CHANNEL, which is the fallback.
$assert($parse()->chat === '@env_channel', 'TELEGRAM_CHANNEL should be used without an argument.');
$assert($parse(['chat' => ' @arg '])->chat === '@arg', 'The chat argument should override TELEGRAM_CHANNEL.');
$rejects(['envChat' => null], 'Give the chat');
$rejects(['envChat' => '  '], 'Give the chat');

// Directory: downloads/<chat> in the project by default; --output relative to the working directory.
$assert($parse()->directory === '/project/downloads/env_channel', 'The default directory should be downloads/<chat>.');
$assert($parse(['chat' => 'https://t.me/other'])->directory === '/project/downloads/other', 'The default directory should follow the chat.');
$assert($parse(['output' => 'videos'])->directory === '/home/user/work/videos', 'A relative --output should be relative to the working directory.');
$assert($parse(['output' => '/data/tg'])->directory === '/data/tg', 'An absolute --output should be kept.');

// Type.
$assert($parse()->type === '', 'No --type should mean every media.');
$assert($parse(['type' => 'Video'])->type === 'video', '--type should be case-insensitive.');
$rejects(['type' => 'gif'], 'photo, video, document, audio');

// Limit.
$assert($parse()->limit === null && $parse(['limit' => '25'])->limit === 25, '--limit should be parsed.');
foreach (['0', '-1', 'abc', '1.5', ''] as $badLimit) {
    $rejects(['limit' => $badLimit], '--limit');
}

// Since.
$assert($parse()->since === null, 'No --since should mean the whole history.');
$assert($parse(['since' => '2024-01-31'])->since === (new DateTimeImmutable('2024-01-31'))->getTimestamp(), '--since should accept a date.');
$weekAgo = $parse(['since' => '7 days ago'])->since;
$assert($weekAgo !== null && abs($weekAgo - (time() - 7 * 86400)) < 60, '--since should accept a relative date.');
$rejects(['since' => 'not a date'], '--since');
$rejects(['since' => ''], '--since');

$assert($parse(['rescan' => true])->rescan, '--rescan should be passed through.');

// MediaType: shared by the web filter and the command.
$photo = ['media' => ['_' => 'messageMediaPhoto']];
$video = ['media' => ['_' => 'messageMediaDocument', 'document' => ['mime_type' => 'video/mp4']]];
$audio = ['media' => ['_' => 'messageMediaDocument', 'document' => ['mime_type' => 'audio/ogg']]];
$file = ['media' => ['_' => 'messageMediaDocument', 'document' => ['mime_type' => 'application/zip']]];
$preview = ['media' => ['_' => 'messageMediaWebPage']];
$text = ['message' => 'hello'];
$expectations = [
    '' => [true, true, true, true, false, false],
    'photo' => [true, false, false, false, false, false],
    'video' => [false, true, false, false, false, false],
    'audio' => [false, false, true, false, false, false],
    'document' => [false, true, true, true, false, false],
];
foreach ($expectations as $filter => $expected) {
    $actual = array_map(static fn (array $message): bool => MediaType::matches($message, (string) $filter), [$photo, $video, $audio, $file, $preview, $text]);
    $assert($actual === $expected, "MediaType::matches with \"$filter\" returned " . json_encode($actual) . '.');
}
$assert(
    [MediaType::of($photo), MediaType::of($video), MediaType::of($audio), MediaType::of($file), MediaType::of($preview), MediaType::of($text)]
        === ['photo', 'video', 'audio', 'document', 'WebPage', ''],
    'MediaType::of should name each media type like the web page did.'
);
$assert(MediaType::searchFilter('video') === ['_' => 'inputMessagesFilterVideo'] && MediaType::searchFilter('') === null, 'Search filters should map types to Telegram filters.');

// FileSize: shared by the web page and the terminal.
$assert(FileSize::format(0) === '0 B' && FileSize::format(512) === '512 B', 'Small sizes should be shown in bytes.');
$assert(FileSize::format(1536) === '1.5 KB' && FileSize::format(48_500_000) === '46.25 MB', 'Larger sizes should be rounded in the right unit.');
$assert(FileSize::format(48_500_000, 1) === '46.3 MB', 'The precision should be configurable.');

fwrite(STDOUT, "Download options tests passed.\n");
