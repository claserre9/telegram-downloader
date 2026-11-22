<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use claserre9\commands\ExportMessagesCommand;
use claserre9\DownloadState;
use claserre9\MessageExporter;
use claserre9\MessageJson;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

// ---------------------------------------------------------------- Records

$peers = MessageJson::peersFrom([
    'users' => [
        ['_' => 'user', 'id' => 11, 'first_name' => 'Ana', 'last_name' => 'Pierre', 'username' => 'ana'],
        ['_' => 'user', 'id' => 12, 'first_name' => 'Bo'],
        ['_' => 'user', 'id' => 13, 'deleted' => true],
    ],
    'chats' => [['_' => 'channel', 'id' => -1001234567890, 'title' => 'News', 'username' => 'news']],
]);
$assert($peers[11] === ['id' => 11, 'name' => 'Ana Pierre', 'username' => 'ana'], 'A user should have a full name and username.');
$assert($peers[12] === ['id' => 12, 'name' => 'Bo'], 'A user without username should have no username key.');
$assert($peers[13]['name'] === 'Deleted Account', 'A deleted user should be named.');
$assert($peers[-1001234567890]['name'] === 'News', 'A channel should be named by its title.');

$self = ['id' => 99, 'name' => 'Me'];
$public = new MessageJson(['id' => -1001234567890, 'type' => 'channel', 'username' => 'news', 'title' => 'News'], $self, static fn (array $message): string => 'file_' . $message['id'] . '.bin');
$private = new MessageJson(['id' => -1009876543210, 'type' => 'supergroup', 'title' => 'Secret'], $self);
$dm = new MessageJson(['id' => 12, 'type' => 'user', 'title' => 'Bo'], $self);

$text = $public->convert([
    '_' => 'message', 'id' => 42, 'peer_id' => -1001234567890, 'from_id' => 11, 'date' => 1700000000, 'edit_date' => 1700000600,
    'message' => 'Hello 👋 world', 'views' => 17, 'pinned' => true, 'grouped_id' => 13865874093846734,
    'entities' => [
        ['_' => 'messageEntityBold', 'offset' => 0, 'length' => 5],
        ['_' => 'messageEntityTextUrl', 'offset' => 9, 'length' => 5, 'url' => 'https://example.com'],
    ],
    'reply_to' => ['_' => 'messageReplyHeader', 'reply_to_scheduled' => false, 'quote' => true, 'reply_to_msg_id' => 40, 'reply_to_top_id' => 30, 'quote_text' => 'earlier'],
    'fwd_from' => ['_' => 'messageFwdHeader', 'imported' => false, 'from_id' => -1001234567890, 'date' => 1690000000, 'channel_post' => 7],
], $peers);
$assert($text !== null, 'A text message should be exported.');
$assert(array_key_first($text) === 'id' && $text['id'] === 42, 'Records should start with the message ID.');
$assert($text['chat'] === ['id' => -1001234567890, 'username' => 'news', 'title' => 'News'], 'Records should name their chat.');
$assert($text['link'] === 'https://t.me/news/42', 'A public chat should link to t.me/<username>/<id>.');
$assert($text['date'] === '2023-11-14T22:13:20Z' && $text['edited'] === '2023-11-14T22:23:20Z', 'Dates should be ISO 8601 in UTC.');
$assert($text['from'] === ['id' => 11, 'name' => 'Ana Pierre', 'username' => 'ana'], 'The sender should be resolved from the batch.');
$assert($text['text'] === 'Hello 👋 world', 'The text should be kept as is.');
$assert($text['entities'] === [
    ['type' => 'bold', 'offset' => 0, 'length' => 5],
    ['type' => 'text_url', 'offset' => 9, 'length' => 5, 'url' => 'https://example.com'],
], 'Entities should have snake_case types and their extra fields.');
$assert($text['reply_to'] === ['id' => 40, 'top_id' => 30, 'quote' => 'earlier'], 'Replies should keep the replied ID, thread and quote.');
$assert($text['forwarded_from'] === ['id' => -1001234567890, 'name' => 'News', 'username' => 'news', 'message_id' => 7, 'date' => '2023-07-22T04:26:40Z'], 'Forwards should name their origin.');
$assert($text['grouped_id'] === '13865874093846734', 'Album IDs should be strings, as they exceed JavaScript integers.');
$assert($text['views'] === 17 && $text['pinned'] === true, 'Views and the pinned flag should be kept.');
$assert(!isset($text['media']), 'A text message should have no media.');
$line = MessageJson::line($text);
$assert(str_starts_with($line, '{"id":42,') && str_ends_with($line, "}\n") && substr_count($line, "\n") === 1, 'A record should be one JSON line.');
$assert(str_contains($line, '👋') && str_contains($line, 'https://example.com'), 'Lines should keep Unicode and slashes readable.');

$post = $private->convert(['_' => 'message', 'id' => 5, 'peer_id' => -1009876543210, 'date' => 1, 'message' => '', 'post_author' => 'Editor', 'edit_date' => 2, 'edit_hide' => true], $peers);
$assert($post['link'] === 'https://t.me/c/9876543210/5', 'A private channel should link to t.me/c/<id>/<message>.');
$assert($post['from'] === ['id' => -1009876543210, 'name' => ''], 'A channel post is sent by the channel.');
$assert($post['author'] === 'Editor', 'A post signature should be kept.');
$assert(!isset($post['edited']), 'Hidden edits (by bots) should not be reported.');

$incoming = $dm->convert(['_' => 'message', 'id' => 3, 'peer_id' => 12, 'date' => 1, 'message' => 'hi'], $peers);
$outgoing = $dm->convert(['_' => 'message', 'id' => 4, 'peer_id' => 12, 'out' => true, 'date' => 1, 'message' => 'yo'], $peers);
$assert(!isset($incoming['link']), 'Private chats have no message links.');
$assert($incoming['from'] === ['id' => 12, 'name' => 'Bo'] && $outgoing['from'] === $self, 'Private messages are sent by the other person or the account.');

$service = $public->convert(['_' => 'messageService', 'id' => 6, 'peer_id' => -1001234567890, 'from_id' => 12, 'date' => 1, 'action' => ['_' => 'messageActionChatAddUser', 'users' => [11, 12]]], $peers);
$assert($service['service'] === ['type' => 'chat_add_user', 'users' => [11, 12]] && !isset($service['text']), 'Service messages should describe their action.');
$assert($public->convert(['_' => 'messageEmpty', 'id' => 8], $peers) === null, 'Empty messages should be skipped.');

$media = static fn (array $media): array => $public->convert(['_' => 'message', 'id' => 9, 'peer_id' => -1001234567890, 'date' => 1, 'message' => 'caption', 'media' => $media], $peers)['media'];
$photo = $media(['_' => 'messageMediaPhoto', 'spoiler' => true, 'photo' => ['_' => 'photo', 'sizes' => [
    ['_' => 'photoStrippedSize', 'type' => 'i', 'bytes' => 'x'],
    ['_' => 'photoSize', 'type' => 'm', 'w' => 320, 'h' => 240, 'size' => 1000],
    ['_' => 'photoSizeProgressive', 'type' => 'y', 'w' => 1280, 'h' => 960, 'sizes' => [100, 5000, 90000]],
]]]);
$assert($photo === ['type' => 'photo', 'file' => 'file_9.bin', 'size' => 90000, 'width' => 1280, 'height' => 960, 'spoiler' => true], 'A photo should be described by its largest size.');
$video = $media(['_' => 'messageMediaDocument', 'document' => ['mime_type' => 'video/mp4', 'size' => 2658170409, 'attributes' => [
    ['_' => 'documentAttributeVideo', 'round_message' => false, 'duration' => 45.834, 'w' => 1448, 'h' => 1086],
    ['_' => 'documentAttributeFilename', 'file_name' => 'Abi 2.mp4'],
]]]);
$assert($video === ['type' => 'video', 'file' => 'file_9.bin', 'name' => 'Abi 2.mp4', 'mime' => 'video/mp4', 'size' => 2658170409, 'duration' => 45.834, 'width' => 1448, 'height' => 1086], 'A video should keep its name, size, duration and dimensions.');
$voice = $media(['_' => 'messageMediaDocument', 'document' => ['mime_type' => 'audio/ogg', 'size' => 10, 'attributes' => [['_' => 'documentAttributeAudio', 'voice' => true, 'duration' => 36, 'waveform' => [1, 2]]]]]);
$assert($voice['type'] === 'voice' && $voice['duration'] === 36 && !isset($voice['waveform']), 'A voice note should be typed, without its waveform.');
$song = $media(['_' => 'messageMediaDocument', 'document' => ['mime_type' => 'audio/mpeg', 'size' => 10, 'attributes' => [['_' => 'documentAttributeAudio', 'voice' => false, 'duration' => 200, 'title' => 'Song', 'performer' => 'Band']]]]);
$assert($song['type'] === 'audio' && $song['title'] === 'Song' && $song['performer'] === 'Band', 'Music should keep its title and performer.');
$sticker = $media(['_' => 'messageMediaDocument', 'document' => ['mime_type' => 'image/webp', 'size' => 10, 'attributes' => [
    ['_' => 'documentAttributeImageSize', 'w' => 512, 'h' => 512],
    ['_' => 'documentAttributeSticker', 'alt' => '🍿', 'stickerset' => ['_' => 'inputStickerSetEmpty']],
]]]);
$assert($sticker['type'] === 'sticker' && $sticker['emoji'] === '🍿' && $sticker['width'] === 512, 'A sticker should keep its emoji and size.');
$roundVideo = $media(['_' => 'messageMediaDocument', 'document' => ['mime_type' => 'video/mp4', 'attributes' => [['_' => 'documentAttributeVideo', 'round_message' => true, 'duration' => 5, 'w' => 240, 'h' => 240]]]]);
$gif = $media(['_' => 'messageMediaDocument', 'document' => ['mime_type' => 'video/mp4', 'attributes' => [['_' => 'documentAttributeAnimated'], ['_' => 'documentAttributeVideo', 'duration' => 2, 'w' => 1, 'h' => 1]]]]);
$file = $media(['_' => 'messageMediaDocument', 'document' => ['mime_type' => 'application/pdf', 'size' => 5, 'attributes' => [['_' => 'documentAttributeFilename', 'file_name' => 'a.pdf']]]]);
$assert($roundVideo['type'] === 'video_note' && $gif['type'] === 'gif' && $file['type'] === 'document', 'Round videos, GIFs and files should be typed.');
$web = $media(['_' => 'messageMediaWebPage', 'webpage' => ['_' => 'webPage', 'id' => 5, 'url' => 'https://example.com/a', 'site_name' => 'Example', 'title' => 'A', 'hash' => 3, 'photo' => ['_' => 'photo']]]);
$assert($web === ['type' => 'webpage', 'url' => 'https://example.com/a', 'site_name' => 'Example', 'title' => 'A'], 'A link preview should keep its URL and title, without a file.');
$geo = $media(['_' => 'messageMediaVenue', 'geo' => ['_' => 'geoPoint', 'lat' => 18.5, 'long' => -72.3, 'access_hash' => 1], 'title' => 'Place', 'address' => 'Street']);
$assert($geo === ['type' => 'venue', 'latitude' => 18.5, 'longitude' => -72.3, 'title' => 'Place', 'address' => 'Street'], 'A venue should keep its coordinates and address.');
$poll = $media(['_' => 'messageMediaPoll',
    'poll' => ['_' => 'poll', 'closed' => true, 'quiz' => false, 'question' => ['_' => 'textWithEntities', 'text' => 'Best?', 'entities' => []],
        'answers' => [['_' => 'pollAnswer', 'text' => ['_' => 'textWithEntities', 'text' => 'A'], 'option' => '0'], ['_' => 'pollAnswer', 'text' => 'B', 'option' => '1']]],
    'results' => ['_' => 'pollResults', 'results' => [['_' => 'pollAnswerVoters', 'option' => '1', 'voters' => 4]], 'total_voters' => 4],
]);
$assert($poll === ['type' => 'poll', 'question' => 'Best?', 'answers' => [['text' => 'A'], ['text' => 'B', 'voters' => 4]], 'total_voters' => 4, 'closed' => true], 'A poll should keep its question, answers and votes.');
$dice = $media(['_' => 'messageMediaDice', 'emoticon' => '🎲', 'value' => 4]);
$assert($dice === ['type' => 'dice', 'emoji' => '🎲', 'value' => 4], 'A dice should keep its value.');

$failing = new MessageJson(['id' => 1, 'type' => 'user'], null, static fn (array $message): string => throw new RuntimeException('expired'));
$expired = $failing->convert(['_' => 'message', 'id' => 1, 'date' => 1, 'message' => '', 'media' => ['_' => 'messageMediaPhoto', 'photo' => ['_' => 'photoEmpty']]], []);
$assert($expired['media'] === ['type' => 'photo'], 'Media that cannot be described should be kept without a file name.');

// ---------------------------------------------------------------- Exporter

/**
 * Chat with message IDs 1..$newest (message N dated N * 1000, sent by user N % 3), paged like messages.getHistory.
 * Message IDs in $gaps were deleted; messages with an ID divisible by 10 have a photo.
 */
final class FakeChat
{
    public int $calls = 0;
    public ?int $failAtCall = null;

    /** @param list<int> $gaps */
    public function __construct(public int $newest, private readonly array $gaps = [])
    {
    }

    /** @return array<string, mixed> */
    public function fetch(int $offsetId, int $minId, int $limit): array
    {
        if ($this->failAtCall !== null && $this->calls === $this->failAtCall) {
            throw new RuntimeException('Simulated Ctrl+C');
        }
        $this->calls++;
        $messages = [];
        for ($id = $offsetId === 0 ? $this->newest : $offsetId - 1; $id > $minId && count($messages) < $limit; $id--) {
            if (in_array($id, $this->gaps, true)) {
                continue;
            }
            $message = ['_' => 'message', 'id' => $id, 'peer_id' => -1001, 'from_id' => $id % 3 + 1, 'date' => $id * 1000, 'message' => "Message $id"];
            if ($id % 10 === 0) {
                $message['media'] = ['_' => 'messageMediaPhoto', 'photo' => ['_' => 'photo', 'sizes' => []]];
            }
            $messages[] = $message;
        }

        return [
            'messages' => $messages,
            'users' => array_map(static fn (int $id): array => ['_' => 'user', 'id' => $id, 'first_name' => "User $id"], [1, 2, 3]),
            'chats' => [],
        ];
    }
}

$directory = sys_get_temp_dir() . '/telegram-export-test-' . bin2hex(random_bytes(4));
$filesystem = new Filesystem();
$filesystem->mkdir($directory);
$format = new MessageJson(['id' => -1001, 'type' => 'supergroup', 'username' => 'group'], null, static fn (array $message): string => "photo_{$message['id']}.jpg");
$statePath = DownloadState::exportPathFor($directory);
$export = static function (FakeChat $chat, ?int $limit = null, ?int $since = null, ?Closure $onMedia = null) use ($format, $directory, $statePath): array {
    $exporter = new MessageExporter($chat->fetch(...), $format, $directory, $limit, $since, $onMedia);
    return $exporter->run(DownloadState::load($statePath));
};
/** @return list<int> */
$exportedIds = static function () use ($directory): array {
    $lines = file(MessageExporter::pathIn($directory), FILE_IGNORE_NEW_LINES);
    return array_map(static fn (string $line): int => json_decode($line, true, 512, JSON_THROW_ON_ERROR)['id'], $lines === false ? [] : $lines);
};
$assertExactly = static function (array $expected, string $message) use ($assert, $exportedIds): void {
    $ids = $exportedIds();
    $sorted = $ids;
    rsort($sorted);
    $assert(count($ids) === count(array_unique($ids)), "$message: no message should be exported twice.");
    $assert($sorted === $expected, "$message: expected " . count($expected) . ' messages, got ' . count($ids) . '.');
};
$range = static fn (int $high, int $low, array $gaps = []): array => array_values(array_diff(range($high, $low), $gaps));

try {
    $chat = new FakeChat(250, [77, 78]);
    $summary = $export($chat);
    $assert($summary === ['exported' => 248, 'complete' => true, 'mediaStopped' => false, 'restarted' => false], 'The first run should export the whole history.');
    $assertExactly($range(250, 1, [77, 78]), 'Full export');
    $first = json_decode((string) strtok((string) file_get_contents(MessageExporter::pathIn($directory)), "\n"), true);
    $assert($first['id'] === 250 && $first['from'] === ['id' => 2, 'name' => 'User 2'] && $first['media']['file'] === 'photo_250.jpg', 'Lines should be full records.');

    $chat->calls = 0;
    $summary = $export($chat);
    $assert($summary['exported'] === 0 && $summary['complete'] && $chat->calls === 1, 'A rerun without new messages should only check the newest message.');

    $chat->newest = 263;
    $summary = $export($chat);
    $assert($summary['exported'] === 13, 'A rerun should export only the new messages.');
    $assertExactly($range(263, 1, [77, 78]), 'Incremental export');
    $assert(array_slice($exportedIds(), -13) === range(263, 251), 'New messages should be appended at the end.');

    // Interrupted in the middle of a run: the next one resumes without duplicates or gaps.
    $filesystem->remove([$statePath, MessageExporter::pathIn($directory)]);
    $chat = new FakeChat(450);
    $chat->failAtCall = 3;
    try {
        $export($chat);
        $assert(false, 'The simulated interruption should stop the run.');
    } catch (RuntimeException $e) {
        $assert($e->getMessage() === 'Simulated Ctrl+C', 'The interruption should be passed on.');
    }
    $assertExactly($range(450, 251), 'Interrupted export');
    // Lines written after the last saved progress (a crash between writing and saving) are dropped on resume.
    file_put_contents(MessageExporter::pathIn($directory), MessageJson::line(['id' => 250, 'partial' => true]) . '{"id":249,"trunc', FILE_APPEND);
    $chat->failAtCall = null;
    $summary = $export($chat);
    $assert($summary['exported'] === 250 && $summary['complete'], 'The resumed run should export the remaining messages.');
    $assertExactly($range(450, 1), 'Resumed export');
    $assert(!str_contains((string) file_get_contents(MessageExporter::pathIn($directory)), 'partial'), 'Unsaved lines should be dropped.');

    // --limit and --since leave older messages for a later run.
    $filesystem->remove([$statePath, MessageExporter::pathIn($directory)]);
    $chat = new FakeChat(300);
    $summary = $export($chat, 120);
    $assert($summary['exported'] === 120 && !$summary['complete'], '--limit should stop the run.');
    $assertExactly($range(300, 181), 'Limited export');
    $summary = $export($chat, null, 150 * 1000);
    $assert($summary['exported'] === 31 && !$summary['complete'], '--since should stop at older messages.');
    $assertExactly($range(300, 150), 'Export since a date');
    $summary = $export($chat);
    $assert($summary['exported'] === 149 && $summary['complete'], 'A run without limits should finish the history.');
    $assertExactly($range(300, 1), 'Completed export');

    // --media: files are fetched before their message is written; a full disk stops before that message.
    $filesystem->remove([$statePath, MessageExporter::pathIn($directory)]);
    $chat = new FakeChat(60);
    $downloaded = [];
    $summary = $export($chat, null, null, static function (array $message) use (&$downloaded): bool {
        if ($message['id'] === 30) {
            return false;
        }
        $downloaded[] = $message['id'];
        return true;
    });
    $assert($summary['mediaStopped'] && !$summary['complete'] && $downloaded === [60, 50, 40], 'A full disk should stop the export at that file.');
    $assertExactly($range(60, 31), 'Export stopped by media');
    $summary = $export($chat, null, null, static function (array $message) use (&$downloaded): bool {
        $downloaded[] = $message['id'];
        return true;
    });
    $assert($summary['complete'] && $downloaded === [60, 50, 40, 30, 20, 10], 'The next run should resume with the file that did not fit.');
    $assertExactly($range(60, 1), 'Export resumed after media');

    // A deleted or truncated messages.jsonl makes the next run start over instead of leaving holes.
    $filesystem->remove(MessageExporter::pathIn($directory));
    $summary = $export($chat);
    $assert($summary['restarted'] && $summary['exported'] === 60, 'A missing messages.jsonl should be exported again.');
    $assertExactly($range(60, 1), 'Restarted export');

    // A new state (--rescan) replaces the existing file.
    $filesystem->remove($statePath);
    $summary = $export($chat);
    $assert(!$summary['restarted'] && $summary['exported'] === 60, '--rescan should export everything again.');
    $assertExactly($range(60, 1), 'Rescanned export');

    // messages.json: one array, oldest first, one record per message.
    file_put_contents(MessageExporter::pathIn($directory), MessageJson::line(['id' => 5, 'text' => 'newer copy']), FILE_APPEND);
    $count = MessageExporter::writeJson($directory);
    $json = json_decode((string) file_get_contents($directory . '/' . MessageExporter::JSON_FILE), true, 512, JSON_THROW_ON_ERROR);
    $assert($count === 60 && array_column($json, 'id') === range(1, 60), 'messages.json should hold each message once, oldest first.');
    $assert($json[4] === ['id' => 5, 'text' => 'newer copy'], 'The last line of a message should win.');
    $assert(!is_file($directory . '/' . MessageExporter::JSON_FILE . '.tmp'), 'No temporary file should be left.');

    // The command: registered as tdx, options checked before logging in.
    $application = new Application();
    $application->add(new ExportMessagesCommand());
    $assert($application->find('tdx') instanceof ExportMessagesCommand, 'The command should be available as tdx.');
    $savedEnv = $_ENV;
    $_ENV['TELEGRAM_API_ID'] = '1';
    $_ENV['TELEGRAM_API_HASH'] = 'x';
    unset($_ENV['TELEGRAM_CHANNEL']);
    $tester = new CommandTester($application->find('tdx'));
    $assert($tester->execute(['chat' => '@group', '--format' => 'xml']) === 2 && str_contains($tester->getDisplay(), '--format must be one of: jsonl, json'), 'An unknown format should be rejected.');
    $assert($tester->execute(['chat' => '@group', '--limit' => '0']) === 2 && str_contains($tester->getDisplay(), 'positive number of messages'), 'A bad limit should be rejected.');
    $assert($tester->execute([]) === 2 && str_contains($tester->getDisplay(), 'tdx @my_channel'), 'A missing chat should be explained.');
    $_ENV = $savedEnv;

    $record = ExportMessagesCommand::chatRecord(['bot_api_id' => -1001, 'type' => 'supergroup', 'Chat' => ['_' => 'channel', 'id' => -1001, 'title' => 'G', 'username' => 'g']]);
    $assert($record === ['id' => -1001, 'type' => 'supergroup', 'username' => 'g', 'title' => 'G'], 'A chat should be described by its info.');
    $record = ExportMessagesCommand::chatRecord(['bot_api_id' => 12, 'type' => 'user', 'User' => ['_' => 'user', 'id' => 12, 'first_name' => 'Bo', 'last_name' => 'X']]);
    $assert($record === ['id' => 12, 'type' => 'user', 'title' => 'Bo X'], 'A private chat should be named after the person.');
} finally {
    $filesystem->remove($directory);
}

echo "Message export tests passed.\n";
