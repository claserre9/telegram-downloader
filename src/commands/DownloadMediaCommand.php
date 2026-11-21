<?php

declare(strict_types=1);

namespace claserre9\commands;

use Amp\DeferredCancellation;
use Closure;
use claserre9\DownloadState;
use claserre9\MediaDownloader;
use claserre9\TelegramClient;
use claserre9\TerminalQrLogin;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Throwable;

use function Amp\delay;

#[AsCommand(
    name: 'telegram:download:media',
    description: 'Download the photos and files of a Telegram chat',
    aliases: ['tdm']
)]
class DownloadMediaCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('chat', InputArgument::OPTIONAL, 'Chat to download from: @username, t.me link or ID (default: TELEGRAM_CHANNEL)')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Only this media type: photo, video, audio or document (any file)')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Download directory (default: downloads/<chat> in the project)')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Stop after this many files, newest first')
            ->addOption('since', 's', InputOption::VALUE_REQUIRED, 'Only messages from this date, e.g. 2024-01-31 or "7 days ago"')
            ->addOption('rescan', null, InputOption::VALUE_NONE, 'Scan the whole history again instead of only new messages')
            ->setHelp(<<<'HELP'
                Downloads every photo and file (videos, audio, voice notes, GIFs, documents) of a chat,
                newest first. Log in by scanning the QR code shown on the first run.

                Progress is saved in the download directory: the next run only scans new messages,
                resumes an interrupted run, and retries failed files. Files already downloaded are
                skipped. Use --rescan after deleting files you want back.

                Examples:
                  <info>php bin/console tdm @my_channel</info>
                  <info>php bin/console tdm @my_channel --type=video --since="30 days ago"</info>
                  <info>php bin/console tdm https://t.me/my_channel --limit=20 -o ~/Videos/telegram</info>
                HELP)
        ;
    }

    /**
     * The command's MadelineProto session: session.madeline at the project root, or TELEGRAM_CLI_SESSION
     * (relative to the project root) when set, e.g. to keep it in a Docker volume. Never depends on the
     * working directory.
     */
    /**
     * Stops the worker's download of the current file before this command exits.
     */
    private static function cancelInWorker(DeferredCancellation $cancellation): void
    {
        $cancellation->cancel();
        try {
            // The worker learns about the cancellation over IPC: give the message time to be sent.
            delay(0.5);
        } catch (Throwable) {
            // A second Ctrl+C: exit right away.
        }
    }

    public static function sessionPath(): string
    {
        $configured = $_ENV['TELEGRAM_CLI_SESSION'] ?? getenv('TELEGRAM_CLI_SESSION');
        if (is_string($configured) && trim($configured) !== '') {
            return Path::makeAbsolute(trim($configured), self::projectRoot());
        }

        return self::projectRoot() . DIRECTORY_SEPARATOR . 'session.madeline';
    }

    /**
     * Folder name for a chat identifier such as "@name", "name", "https://t.me/name" or "-100123".
     */
    public static function chatFolder(string $chat): string
    {
        $chat = trim($chat);
        if (preg_match('~^(?:https?://)?(?:t\.me|telegram\.me)/(?:s/)?([^/?#]+)~i', $chat, $matches) === 1) {
            $chat = $matches[1];
        }
        $folder = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', ltrim($chat, '@')), '_');

        return $folder === '' ? 'chat' : $folder;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!isset($_ENV['TELEGRAM_API_ID'], $_ENV['TELEGRAM_API_HASH'])) {
            $io->error('TELEGRAM_API_ID and TELEGRAM_API_HASH must be set (see .env.dev).');
            return Command::FAILURE;
        }

        // Options are checked before logging in, so a typo fails immediately.
        try {
            $options = DownloadOptions::parse(
                $input->getArgument('chat'),
                $_ENV['TELEGRAM_CHANNEL'] ?? null,
                $input->getOption('type'),
                $input->getOption('output'),
                $input->getOption('limit'),
                $input->getOption('since'),
                (bool) $input->getOption('rescan'),
                self::projectRoot(),
                (string) getcwd(),
            );
        } catch (InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        }

        // Absolute path, so the same session (and login) is reused whatever directory the command runs from.
        $client = new TelegramClient(
            (int) $_ENV['TELEGRAM_API_ID'],
            (string) $_ENV['TELEGRAM_API_HASH'],
            self::sessionPath()
        );
        try {
            $client->start(
                static function (string $message) use ($output): void {
                    $output->writeln($message);
                },
                static fn (string $prompt): string => (string) $io->askHidden($prompt),
            );
        } catch (RuntimeException $e) {
            if (TerminalQrLogin::wasInterrupted($e)) {
                $io->warning('Login cancelled.');
                return Command::FAILURE;
            }
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $madeline = $client->getMadeline();
        if (!$madeline) {
            $io->error('Failed to connect to telegram');
            return Command::FAILURE;
        }

        $chat = $options->chat;
        try {
            $madeline->getInfo($chat);
        } catch (Throwable $e) {
            $io->error("Cannot access the chat \"$chat\": {$e->getMessage()}");
            return Command::FAILURE;
        }

        $filesystem = new Filesystem();
        if (!$filesystem->exists($options->directory)) {
            $io->comment("Creating download directory: {$options->directory}");
            $filesystem->mkdir($options->directory);
        }

        $statePath = DownloadState::pathFor($options->directory, $options->type);
        if ($options->rescan) {
            $filesystem->remove($statePath);
        }
        $state = DownloadState::load($statePath);

        $downloader = new MediaDownloader(
            static function (int $offsetId, int $minId, int $limit) use ($madeline, $chat): array {
                /** @var array{messages: list<array<string, mixed>>} $history */
                $history = $madeline->messages->getHistory(
                    peer: $chat,
                    offset_id: $offsetId,
                    min_id: $minId,
                    limit: $limit,
                );
                return $history['messages'];
            },
            static function (array $message) use ($madeline): array {
                $info = $madeline->getDownloadInfo($message);
                return ['name' => $info['name'], 'ext' => $info['ext'], 'size' => $info['size']];
            },
            static function (array $message, string $path, Closure $progress) use ($madeline): void {
                // The download runs in MadelineProto's worker process, which outlives this command: without an
                // explicit cancellation, Ctrl+C would leave it downloading, and the next run would wait for it.
                $cancellation = new DeferredCancellation();
                try {
                    $madeline->downloadToFile(
                        $message,
                        $path,
                        static function (float $percent, float $speed) use ($progress): void {
                            $progress($percent, $speed);
                        },
                        $cancellation->getCancellation(),
                    );
                } catch (Throwable $e) {
                    if (TerminalQrLogin::wasInterrupted($e)) {
                        self::cancelInWorker($cancellation);
                    }
                    throw $e;
                }
            },
            $options->directory,
            $options->type,
            $options->limit,
            $options->since,
        );

        $what = $options->type === '' ? 'photos and files' : $options->type . ' files';
        $scope = $state->knownTop > 0 ? 'new messages' : 'the whole history';
        $io->text("Downloading $what from $chat ($scope) to {$options->directory}");
        $observer = new ConsoleMediaDownloadObserver($io, $output);
        try {
            $summary = $downloader->run($observer, $state);
        } catch (Throwable $e) {
            $observer->abortProgress();
            if (TerminalQrLogin::wasInterrupted($e)) {
                $io->warning('Download interrupted. Run the command again to resume where it stopped.');
            } else {
                $io->error("Download stopped: {$e->getMessage()}");
            }
            return Command::FAILURE;
        }

        $io->definitionList(
            ['Messages scanned' => (string) $summary['messages']],
            ['Downloaded' => (string) $summary['downloaded']],
            ['Already downloaded' => (string) $summary['skipped']],
            ['Failed' => (string) count($summary['failed'])],
        );
        if ($summary['diskFull'] !== null) {
            $full = $summary['diskFull'];
            if ($summary['failed'] !== []) {
                $io->table(
                    ['Message', 'File', 'Error'],
                    array_map(static fn (array $failure): array => ['#' . $failure['id'], $failure['name'], $failure['error']], $summary['failed']),
                );
            }
            $io->error([
                sprintf('Stopped: not enough disk space for %s (message #%d).', $full['name'], $full['id']),
                $full['needed'] > 0
                    ? sprintf(
                        'It needs %s, %s are free and %s are kept free for the system.',
                        ConsoleMediaDownloadObserver::formatSize($full['needed']),
                        ConsoleMediaDownloadObserver::formatSize($full['available']),
                        ConsoleMediaDownloadObserver::formatSize(MediaDownloader::DISK_RESERVE),
                    )
                    : sprintf('The disk filled up during the download (%s free).', ConsoleMediaDownloadObserver::formatSize($full['available'])),
                'Free some space, or move the files with .telegram-downloader*.json to a bigger disk and pass it with --output, '
                    . 'then run the command again: it resumes with this file.',
            ]);
            return Command::FAILURE;
        }
        if ($summary['failed'] !== []) {
            $io->table(
                ['Message', 'File', 'Error'],
                array_map(static fn (array $failure): array => ['#' . $failure['id'], $failure['name'], $failure['error']], $summary['failed']),
            );
            $io->error(count($summary['failed']) . ' file(s) could not be downloaded. Run the command again to retry them.');
            return Command::FAILURE;
        }

        if (!$summary['complete']) {
            $io->success('Done. Older messages remain (--limit or --since); run without them to continue.');
        } elseif ($summary['messages'] === 0) {
            $io->success('Already up to date: no new messages.');
        } else {
            $io->success('All media downloaded.');
        }
        return Command::SUCCESS;
    }

    private static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
