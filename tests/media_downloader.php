<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use claserre9\commands\ConsoleMediaDownloadObserver;
use claserre9\commands\DownloadMediaCommand;
use claserre9\DownloadState;
use claserre9\MediaDownloader;
use claserre9\MediaDownloadObserver;
use Symfony\Component\Filesystem\Filesystem;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

final class RecordingObserver implements MediaDownloadObserver
{
    /** @var list<string> */
    public array $events = [];

    public function fileStarted(int $messageId, string $name, int $size): void
    {
        $this->events[] = "start #$messageId $name $size";
    }

    public function fileProgress(float $percent, float $speedMbps): void
    {
        $this->events[] = "progress $percent";
    }

    public function fileDone(string $path): void
    {
        $this->events[] = 'done ' . basename($path);
    }

    public function fileSkipped(int $messageId, string $name): void
    {
        $this->events[] = "skip #$messageId $name";
    }

    public function fileFailed(int $messageId, string $name, string $error): void
    {
        $this->events[] = "fail #$messageId $error";
    }
}

/**
 * Simulated chat history with message IDs 1..$newest (message N is dated N * 1000), paged like Telegram:
 * fetch(offsetId, minId, limit) returns messages with minId < id < offsetId (offsetId 0 = newest), newest first.
 *
 * @param Closure(int): ?array<string, mixed> $mediaFor Media of a message ID (null = text message).
 * @param list<int> $shortBatches Call numbers (0-based) for which fewer messages than asked are returned.
 */
final class FakeHistory
{
    /** @var list<array{int, int, int}> */
    public array $calls = [];

    public function __construct(public int $newest, private readonly Closure $mediaFor, private readonly array $shortBatches = [])
    {
    }

    /** @return list<array<string, mixed>> */
    public function fetch(int $offsetId, int $minId, int $limit): array
    {
        $call = count($this->calls);
        $this->calls[] = [$offsetId, $minId, $limit];
        // A short batch mimics deleted messages: Telegram returns less than asked although older ones exist.
        $count = in_array($call, $this->shortBatches, true) ? min($limit, 7) : $limit;
        $messages = [];
        for ($id = $offsetId === 0 ? $this->newest : $offsetId - 1; $id > $minId && count($messages) < $count; $id--) {
            $message = ['_' => 'message', 'id' => $id, 'date' => $id * 1000];
            $media = ($this->mediaFor)($id);
            if ($media !== null) {
                $message['media'] = $media;
            }
            $messages[] = $message;
        }
        return $messages;
    }
}

$photoEvery10 = static fn (int $id): ?array => $id % 10 === 0 ? ['_' => 'messageMediaPhoto'] : null;
$describe = static fn (array $message): array => ['name' => 'file_' . $message['id'], 'ext' => '.bin', 'size' => 10];

$filesystem = new Filesystem();
$tempRoot = sys_get_temp_dir() . '/media-downloader-test-' . bin2hex(random_bytes(4));
$newDir = static function (string $name) use ($tempRoot, $filesystem): string {
    $dir = $tempRoot . '/' . $name;
    $filesystem->mkdir($dir);
    return $dir;
};

/**
 * Downloader that records downloaded message IDs (writing the file when $write is true).
 *
 * @param list<int> $downloaded
 * @param Closure(int): void|null $before Called before each download, e.g. to simulate failures.
 */
$downloader = static function (FakeHistory $history, string $dir, array &$downloaded, bool $write = false, ?Closure $before = null, string $type = '', ?int $limit = null, ?int $since = null) use ($describe): MediaDownloader {
    return new MediaDownloader(
        $history->fetch(...),
        $describe,
        static function (array $message, string $path, Closure $progress) use (&$downloaded, $write, $before): void {
            if ($before !== null) {
                $before($message['id']);
            }
            $progress(50.0, 3.0);
            $downloaded[] = $message['id'];
            if ($write) {
                file_put_contents($path, str_repeat('x', 10));
            }
        },
        $dir,
        $type,
        $limit,
        $since,
    );
};

try {
    // 1. Without state, the whole history is scanned even when Telegram returns short batches.
    $dir = $newDir('full');
    $history = new FakeHistory(250, $photoEvery10, [0, 1]);
    $downloaded = [];
    $observer = new RecordingObserver();
    $summary = $downloader($history, $dir, $downloaded, true)->run($observer);
    $assert($summary['messages'] === 250 && $summary['complete'], 'Every message should be scanned despite short batches; scanned ' . $summary['messages'] . '.');
    $assert(count($downloaded) === 25 && $downloaded[0] === 250 && end($downloaded) === 10, 'Every photo should be downloaded, newest first.');
    for ($i = 2; $i < count($history->calls); $i++) {
        $assert($history->calls[$i][0] < $history->calls[$i - 1][0], 'Each page should move further back in history.');
    }
    $assert(in_array('progress 50', $observer->events, true) && in_array('start #250 file_250.bin 10', $observer->events, true), 'Progress should be reported.');

    // 2. Complete files are skipped; a partial file is downloaded again (MadelineProto resumes it).
    file_put_contents($dir . '/file_30.bin', 'xxx');
    $downloaded = [];
    $summary = $downloader(new FakeHistory(250, $photoEvery10), $dir, $downloaded, true)->run(new RecordingObserver());
    $assert($downloaded === [30] && $summary['skipped'] === 24 && $summary['downloaded'] === 1, 'Only the partial file should be downloaded again.');

    // 3. Type filters.
    $mixed = static fn (int $id): ?array => match ($id) {
        6 => ['_' => 'messageMediaDocument', 'document' => ['mime_type' => 'video/mp4']],
        5 => ['_' => 'messageMediaDocument', 'document' => ['mime_type' => 'audio/mpeg']],
        4 => ['_' => 'messageMediaDocument', 'document' => ['mime_type' => 'application/pdf']],
        3 => ['_' => 'messageMediaPhoto'],
        2 => ['_' => 'messageMediaWebPage'],
        1 => ['_' => 'messageMediaPoll'],
        default => null,
    };
    foreach (['' => [6, 5, 4, 3], 'photo' => [3], 'video' => [6], 'audio' => [5], 'document' => [6, 5, 4]] as $type => $expected) {
        $downloaded = [];
        $summary = $downloader(new FakeHistory(6, $mixed), $newDir('type-' . ($type ?: 'all')), $downloaded, false, null, (string) $type)->run(new RecordingObserver());
        $assert($downloaded === $expected, "--type=$type should download " . json_encode($expected) . ', got ' . json_encode($downloaded) . '.');
        $assert($summary['messages'] === 6, 'All messages should be scanned whatever the type.');
    }

    // 4. A failing file is reported and the others still download; a media that cannot be described too.
    $downloaded = [];
    $observer = new RecordingObserver();
    $summary = $downloader(new FakeHistory(30, $photoEvery10), $newDir('failure'), $downloaded, false, static function (int $id): void {
        if ($id === 20) {
            throw new RuntimeException('FILE_REFERENCE_EXPIRED');
        }
    })->run($observer);
    $assert($downloaded === [30, 10], 'Other files should download after a failure.');
    $assert($summary['failed'] === [['id' => 20, 'name' => 'file_20.bin', 'error' => 'FILE_REFERENCE_EXPIRED']] && !$summary['complete'], 'The failure should be listed and the run marked incomplete.');
    $summary = (new MediaDownloader((new FakeHistory(3, static fn (): array => ['_' => 'messageMediaPhoto']))->fetch(...), static function (): array {
        throw new RuntimeException('no file info');
    }, static fn () => null, $newDir('describe')))->run(new RecordingObserver());
    $assert(count($summary['failed']) === 3 && $summary['failed'][0]['name'] === '#3', 'A media that cannot be described should be reported with its message number.');

    // 5. Ctrl+C stops the whole run instead of being counted as a failed file.
    $interrupted = false;
    $downloaded = [];
    try {
        $downloader(new FakeHistory(30, $photoEvery10), $newDir('interrupt'), $downloaded, false, static function (): void {
            throw new RuntimeException('loop', 0, new Amp\SignalException('SIGINT received'));
        })->run(new RecordingObserver());
    } catch (RuntimeException) {
        $interrupted = true;
    }
    $assert($interrupted, 'An interruption should stop the run.');

    // 6. A history that does not move back (the same page again) stops instead of looping forever.
    $calls = 0;
    (new MediaDownloader(static function () use (&$calls): array {
        $calls++;
        return $calls > 5 ? [] : [['_' => 'message', 'id' => 0]];
    }, $describe, static fn () => null, $newDir('stuck')))->run(new RecordingObserver());
    $assert($calls === 1, 'A page that does not move back in history should end the scan.');

    // 7. --limit stops after N matching files; --since stops at older messages.
    $downloaded = [];
    $summary = $downloader(new FakeHistory(250, $photoEvery10), $newDir('limit'), $downloaded, false, null, '', 3)->run(new RecordingObserver());
    $assert($downloaded === [250, 240, 230] && !$summary['complete'], '--limit should keep the newest N files and mark the run incomplete.');
    $downloaded = [];
    $summary = $downloader(new FakeHistory(250, $photoEvery10), $newDir('since'), $downloaded, false, null, '', null, 200 * 1000)->run(new RecordingObserver());
    $assert($downloaded === [250, 240, 230, 220, 210, 200] && $summary['messages'] === 51 && !$summary['complete'], '--since should stop at the first older message.');

    // ---- With a saved state ----

    // S1. First run scans everything, then only new messages are fetched.
    $dir = $newDir('state');
    $statePath = DownloadState::pathFor($dir, '');
    $history = new FakeHistory(250, $photoEvery10);
    $downloaded = [];
    $summary = $downloader($history, $dir, $downloaded)->run(new RecordingObserver(), DownloadState::load($statePath));
    $state = DownloadState::load($statePath);
    $assert($summary['messages'] === 250 && count($downloaded) === 25 && $summary['complete'], 'The first run should scan the whole history.');
    $assert($state->knownTop === 250 && $state->pending === [], 'The state should record that everything up to the newest message is done.');
    $assert(is_array(json_decode((string) file_get_contents($statePath), true)) && !is_file($statePath . '.tmp'), 'The state file should be valid JSON written atomically.');

    $history->newest = 263;
    $history->calls = [];
    $downloaded = [];
    $summary = $downloader($history, $dir, $downloaded)->run(new RecordingObserver(), DownloadState::load($statePath));
    $assert($summary['messages'] === 13 && $downloaded === [260], 'A rerun should only scan the 13 new messages.');
    $assert(count(array_filter($history->calls, static fn (array $call): bool => $call[1] === 250)) > 0, 'New messages should be fetched with min_id set to the last known message.');
    $assert(DownloadState::load($statePath)->knownTop === 263, 'The state should move to the new newest message.');

    $downloaded = [];
    $summary = $downloader($history, $dir, $downloaded)->run(new RecordingObserver(), DownloadState::load($statePath));
    $assert($summary['messages'] === 0 && $downloaded === [] && $summary['complete'], 'Without new messages nothing should be scanned.');

    // S2. An interrupted run resumes where it stopped, after first handling messages posted since.
    $dir = $newDir('state-interrupt');
    $statePath = DownloadState::pathFor($dir, '');
    $history = new FakeHistory(250, $photoEvery10);
    $downloaded = [];
    try {
        $downloader($history, $dir, $downloaded, false, static function (int $id): void {
            if ($id === 120) {
                throw new RuntimeException('loop', 0, new Amp\SignalException('SIGINT received'));
            }
        })->run(new RecordingObserver(), DownloadState::load($statePath));
    } catch (RuntimeException) {
    }
    $state = DownloadState::load($statePath);
    $assert(count($state->pending) === 1 && $state->pending[0][0] === 0 && $state->pending[0][1] >= 120 && $state->pending[0][1] <= 150, 'The interrupted range should be saved from the last completed batch: ' . json_encode($state->pending));
    $history->newest = 270;
    $resumed = [];
    $summary = $downloader($history, $dir, $resumed)->run(new RecordingObserver(), DownloadState::load($statePath));
    $assert($resumed[0] === 270 && $resumed[1] === 260, 'New messages should be handled first.');
    $all = array_unique(array_merge($downloaded, $resumed));
    sort($all);
    $assert($all === range(10, 270, 10), 'No photo should be missed after resuming: ' . json_encode($all));
    $assert(!in_array(240, $resumed, true), 'Messages finished before the interruption should not be scanned again.');
    $assert(DownloadState::load($statePath)->pending === [] && $summary['complete'], 'The state should be complete after resuming.');

    // S3. Failed files are retried on the next run.
    $dir = $newDir('state-retry');
    $statePath = DownloadState::pathFor($dir, '');
    $history = new FakeHistory(100, $photoEvery10);
    $downloaded = [];
    $summary = $downloader($history, $dir, $downloaded, false, static function (int $id): void {
        if ($id === 40) {
            throw new RuntimeException('timeout');
        }
    })->run(new RecordingObserver(), DownloadState::load($statePath));
    $assert(count($summary['failed']) === 1 && !in_array(40, $downloaded, true), 'The first run should fail on message 40.');
    $downloaded = [];
    $summary = $downloader($history, $dir, $downloaded)->run(new RecordingObserver(), DownloadState::load($statePath));
    $assert(in_array(40, $downloaded, true) && !in_array(50, $downloaded, true), 'The next run should retry the failed file without rescanning newer messages.');
    $assert(DownloadState::load($statePath)->pending === [] && $summary['complete'], 'The state should be complete once the retry succeeds.');

    // S4. --limit with a state: the next run continues with older files.
    $dir = $newDir('state-limit');
    $statePath = DownloadState::pathFor($dir, '');
    $history = new FakeHistory(250, $photoEvery10);
    $first = [];
    $downloader($history, $dir, $first, false, null, '', 5)->run(new RecordingObserver(), DownloadState::load($statePath));
    $rest = [];
    $downloader($history, $dir, $rest)->run(new RecordingObserver(), DownloadState::load($statePath));
    $assert($first === [250, 240, 230, 220, 210] && $rest[0] === 200 && count($rest) === 20, 'A run after --limit should continue with the older files.');

    // S5. Unreadable or foreign state files start from scratch; each type has its own state.
    $corrupt = $tempRoot . '/corrupt.json';
    file_put_contents($corrupt, '{"version": 99}');
    $state = DownloadState::load($corrupt);
    $assert($state->knownTop === 0 && $state->pending === [], 'An unknown state format should be ignored.');
    file_put_contents($corrupt, 'not json');
    $assert(DownloadState::load($corrupt)->knownTop === 0, 'A corrupt state file should be ignored.');
    $assert(DownloadState::pathFor('/d', 'photo') !== DownloadState::pathFor('/d', ''), 'A filtered run must not share the state of a full run.');

    // safeFileName keeps Telegram names inside the download directory.
    $assert(MediaDownloader::safeFileName('../../etc/passwd') === '.._.._etc_passwd', 'Path separators should be neutralized.');
    foreach (['', '.', '..', '  '] as $unsafe) {
        $assert(MediaDownloader::safeFileName($unsafe) === 'file', 'Empty or special names should get a default name.');
    }
} finally {
    $filesystem->remove($tempRoot);
}

// One folder per chat, whatever form the chat takes.
$folders = [
    ['@my_channel', 'my_channel'],
    ['my_channel', 'my_channel'],
    ['https://t.me/my_channel', 'my_channel'],
    ['t.me/s/my_channel?x=1', 'my_channel'],
    ['-1001234567890', '-1001234567890'],
    ['../../etc', 'etc'],
    ['@@@', 'chat'],
];
foreach ($folders as [$chat, $expected]) {
    $assert(DownloadMediaCommand::chatFolder($chat) === $expected, "The folder for \"$chat\" should be \"$expected\".");
}

$assert(ConsoleMediaDownloadObserver::formatSize(0) === 'unknown size', 'An unknown size should be shown as such.');
$assert(ConsoleMediaDownloadObserver::formatSize(5 * 1024 * 1024 + 300 * 1024) === '5.3 MB', 'Large sizes should be shown in MB.');

fwrite(STDOUT, "Media downloader tests passed.\n");
