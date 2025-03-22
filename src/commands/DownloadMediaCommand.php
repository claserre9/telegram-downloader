<?php

namespace claserre9\commands;

use claserre9\TelegramClient;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'telegram:download:media',
    description: 'Download media from telegram',
    aliases: ['tdm']
)]
class DownloadMediaCommand extends Command
{

    protected function configure(): void
    {
        $this->setDescription('Download media from telegram')
            ->setHelp('This command allows you to download media from telegram')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!isset($_ENV['TELEGRAM_API_ID'], $_ENV['TELEGRAM_API_HASH'], $_ENV['TELEGRAM_CHANNEL'])) {
            $io->error('TELEGRAM_CHANNEL, TELEGRAM_API_ID and TELEGRAM_API_HASH env are not set');
            throw new RuntimeException('Required environment variables are missing');
        }

        $limit = 100;
        $lastMessageId = 0;

        $client = new TelegramClient(
            $_ENV['TELEGRAM_API_ID'],
            $_ENV['TELEGRAM_API_HASH']
        );
        $client->start();

        $MadelineProto = $client->getMadeline();
        if (!$MadelineProto) {
            $io->error('Failed to connect to telegram');
            return Command::FAILURE;
        }
        $downloadPath = __DIR__ . '/../../downloads/';

        $filesystem = new Filesystem();
        if (!$filesystem->exists($downloadPath)) {
            $io->comment("Creating download directory: $downloadPath");
            $filesystem->mkdir($downloadPath);
        }

        do {
            $messages = $MadelineProto->messages->getHistory([
                'peer' => $_ENV['TELEGRAM_CHANNEL'],
                'offset_id' => $lastMessageId,
                'limit' => $limit,
                'add_offset' => 0,
                'max_id' => 0,
                'min_id' => 0,
                'hash' => 0
            ]);

            if (empty($messages['messages'])) {
                $io->comment('No messages found.');
                break;
            }

            foreach ($messages['messages'] as $message) {
                $lastMessageId = $message['id'];

                if (isset($message['media'])) {
                    $mediaType = $message['media']['_'];

                    if (in_array($mediaType, ['messageMediaPhoto', 'messageMediaDocument'])) {
                        try {
                            $file = $MadelineProto->downloadToDir($message['media'], $downloadPath);
                            $io->success("Downloaded: $file");
                        } catch (RuntimeException $e) {
                            $io->error("Download error: {$e->getMessage()}");
                        }
                    }
                }
            }

        } while (count($messages['messages']) >= $limit);

        $io->success('Download complete.');
        return Command::SUCCESS;
    }

}