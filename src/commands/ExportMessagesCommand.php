<?php

declare(strict_types=1);

namespace claserre9\commands;

use claserre9\DownloadState;
use claserre9\MediaDownloader;
use claserre9\MessageExporter;
use claserre9\MessageJson;
use claserre9\TerminalQrLogin;
use danog\MadelineProto\API;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

#[AsCommand(
    name: 'telegram:export:messages',
    description: 'Export the messages of a Telegram chat to JSON Lines',
    aliases: ['tdx']
)]
class ExportMessagesCommand extends Command
{
    public const FORMATS = ['jsonl', 'json'];

    protected function configure(): void
    {
        $this
            ->addArgument('chat', InputArgument::OPTIONAL, 'Chat to export: @username, t.me link or ID (default: TELEGRAM_CHANNEL)')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Export directory (default: downloads/<chat> in the project)')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Stop after this many messages, newest first')
            ->addOption('since', 's', InputOption::VALUE_REQUIRED, 'Only messages from this date, e.g. 2024-01-31 or "7 days ago"')
            ->addOption('media', null, InputOption::VALUE_NONE, 'Also download the photos and files of the exported messages')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'jsonl, or json to also write every message to messages.json', 'jsonl')
            ->addOption('rescan', null, InputOption::VALUE_NONE, 'Export the whole history again, replacing messages.jsonl')
            ->setHelp(<<<'HELP'
                Writes every message of a chat to messages.jsonl in the export directory, one JSON object
                per line (text, sender, date, replies, forwards, and a description of each photo or file).
                Log in by scanning the QR code shown on the first run.

                The next run only adds messages posted since, and an interrupted run resumes where it
                stopped. Lines are in export order: sort them by "id" for the chat's order, or use
                --format=json for one messages.json array sorted from the oldest message.

                Photos and files are named as by the download command (tdm), in the same directory, so
                "media.file" points to them once downloaded with --media or tdm.

                Examples:
                  <info>php bin/console tdx @my_channel</info>
                  <info>php bin/console tdx @my_channel --since="30 days ago" --format=json</info>
                  <info>php bin/console tdx https://t.me/my_group --media -o ~/telegram/my_group</info>
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!isset($_ENV['TELEGRAM_API_ID'], $_ENV['TELEGRAM_API_HASH'])) {
            $io->error('TELEGRAM_API_ID and TELEGRAM_API_HASH must be set (see .env.dev).');
            return Command::FAILURE;
        }

        // Options are checked before logging in, so a typo fails immediately.
        $format = strtolower(trim((string) $input->getOption('format')));
        try {
            if (!in_array($format, self::FORMATS, true)) {
                throw new InvalidArgumentException('--format must be one of: ' . implode(', ', self::FORMATS) . '.');
            }
            $options = DownloadOptions::parse(
                $input->getArgument('chat'),
                $_ENV['TELEGRAM_CHANNEL'] ?? null,
                null,
                $input->getOption('output'),
                $input->getOption('limit'),
                $input->getOption('since'),
                (bool) $input->getOption('rescan'),
                DownloadMediaCommand::projectRoot(),
                (string) getcwd(),
                'tdx',
                'messages',
            );
        } catch (InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        }

        $madeline = DownloadMediaCommand::connect($io, $output);
        if ($madeline === null) {
            return Command::FAILURE;
        }

        $chat = $options->chat;
        try {
            $chatRecord = self::chatRecord($madeline->getInfo($chat));
            $self = self::selfRecord($madeline);
        } catch (Throwable $e) {
            $io->error("Cannot access the chat \"$chat\": {$e->getMessage()}");
            return Command::FAILURE;
        }

        $filesystem = new Filesystem();
        if (!$filesystem->exists($options->directory)) {
            $io->comment("Creating export directory: {$options->directory}");
            $filesystem->mkdir($options->directory);
        }

        $statePath = DownloadState::exportPathFor($options->directory);
        if ($options->rescan) {
            $filesystem->remove($statePath);
        }
        $state = DownloadState::load($statePath);
        $scope = $state->isNew() ? 'the whole history' : 'new messages';

        $describe = DownloadMediaCommand::describer($madeline);
        $messageJson = new MessageJson(
            $chatRecord,
            $self,
            static function (array $message) use ($describe): string {
                $info = $describe($message);
                return MediaDownloader::safeFileName($info['name'] . $info['ext']);
            },
        );

        $observer = new ConsoleMediaDownloadObserver($io, $output);
        $files = ['downloaded' => 0, 'skipped' => 0, 'failed' => [], 'diskFull' => null];
        $onMedia = null;
        if ($input->getOption('media')) {
            $downloader = new MediaDownloader(
                static fn (): array => [],
                $describe,
                DownloadMediaCommand::fileDownloader($madeline),
                $options->directory,
            );
            $onMedia = static function (array $message) use ($downloader, $observer, &$files): bool {
                $result = $downloader->downloadOne($message, $observer);
                $files['downloaded'] += $result['downloaded'];
                $files['skipped'] += $result['skipped'];
                array_push($files['failed'], ...$result['failed']);
                $files['diskFull'] = $result['diskFull'];

                return $result['diskFull'] === null;
            };
        }

        $progressBar = null;
        if ($onMedia === null) {
            // Downloads draw their own progress bars; without them, show the number of exported messages.
            $progressBar = $io->createProgressBar();
            $progressBar->setFormat('  %current% messages exported [%elapsed:6s%]');
        }

        $exporter = new MessageExporter(
            static function (int $offsetId, int $minId, int $limit) use ($madeline, $chat): array {
                /** @var array<string, mixed> $history */
                $history = $madeline->messages->getHistory(
                    peer: $chat,
                    offset_id: $offsetId,
                    min_id: $minId,
                    limit: $limit,
                );
                return $history;
            },
            $messageJson,
            $options->directory,
            $options->limit,
            $options->since,
            $onMedia,
            $progressBar === null ? null : static function (int $exported) use ($progressBar): void {
                $progressBar->setProgress($exported);
            },
        );

        $target = MessageExporter::pathIn($options->directory);
        $io->text("Exporting messages from $chat ($scope) to $target");
        try {
            $progressBar?->start();
            $summary = $exporter->run($state);
            $progressBar?->finish();
            $progressBar?->clear();
        } catch (Throwable $e) {
            $progressBar?->clear();
            $observer->abortProgress();
            if (TerminalQrLogin::wasInterrupted($e)) {
                $io->warning('Export interrupted. Run the command again to resume where it stopped.');
            } else {
                $io->error("Export stopped: {$e->getMessage()}");
            }
            return Command::FAILURE;
        }

        if ($summary['restarted']) {
            $io->note('messages.jsonl was missing or shorter than at the last export, so the whole history was exported again.');
        }
        $rows = [['Messages exported' => (string) $summary['exported']]];
        if ($onMedia !== null) {
            $rows[] = ['Files downloaded' => (string) $files['downloaded']];
            $rows[] = ['Files already downloaded' => (string) $files['skipped']];
            $rows[] = ['Files failed' => (string) count($files['failed'])];
        }
        $io->definitionList(...$rows);

        if ($format === 'json') {
            try {
                $count = MessageExporter::writeJson($options->directory);
                $io->text(sprintf('Wrote %d messages to %s', $count, $options->directory . DIRECTORY_SEPARATOR . MessageExporter::JSON_FILE));
            } catch (Throwable $e) {
                $io->error($e->getMessage());
                return Command::FAILURE;
            }
        }

        if ($files['diskFull'] !== null) {
            $full = $files['diskFull'];
            $io->error([
                sprintf('Stopped: not enough disk space for %s (message #%d).', $full['name'], $full['id']),
                'Free some space and run the command again: it resumes with this message.',
            ]);
            return Command::FAILURE;
        }
        if ($files['failed'] !== []) {
            $io->table(
                ['Message', 'File', 'Error'],
                array_map(static fn (array $failure): array => ['#' . $failure['id'], $failure['name'], $failure['error']], $files['failed']),
            );
            $io->error(sprintf(
                '%d file(s) could not be downloaded; their messages are exported. Run "tdm %s" to retry them.',
                count($files['failed']),
                $chat,
            ));
            return Command::FAILURE;
        }

        if (!$summary['complete']) {
            $io->success('Done. Older messages remain (--limit or --since); run without them to continue.');
        } elseif ($summary['exported'] === 0) {
            $io->success('Already up to date: no new messages.');
        } else {
            $io->success('All messages exported.');
        }
        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $info getInfo() result.
     * @return array{id: int, type: string, username?: string, title?: string}
     */
    public static function chatRecord(array $info): array
    {
        $peer = is_array($info['Chat'] ?? null) ? $info['Chat'] : (is_array($info['User'] ?? null) ? $info['User'] : []);
        $id = is_int($info['bot_api_id'] ?? null) ? $info['bot_api_id'] : (is_int($peer['id'] ?? null) ? $peer['id'] : 0);
        $record = ['id' => $id, 'type' => is_string($info['type'] ?? null) ? $info['type'] : 'user'];
        if (is_string($peer['username'] ?? null) && $peer['username'] !== '') {
            $record['username'] = $peer['username'];
        }
        $title = is_string($peer['title'] ?? null)
            ? $peer['title']
            : trim((is_string($peer['first_name'] ?? null) ? $peer['first_name'] : '') . ' ' . (is_string($peer['last_name'] ?? null) ? $peer['last_name'] : ''));
        if ($title !== '') {
            $record['title'] = $title;
        }

        return $record;
    }

    /**
     * @return array{id: int, name: string, username?: string}|null
     */
    private static function selfRecord(API $madeline): ?array
    {
        $self = $madeline->getSelf();
        if (!is_array($self) || !is_int($self['id'] ?? null)) {
            return null;
        }

        return MessageJson::peersFrom(['users' => [$self]])[$self['id']] ?? null;
    }
}
