<?php

use claserre9\TelegramClient;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();


$input = new ArgvInput();
$output = new ConsoleOutput();
$io = new SymfonyStyle($input, $output);

if (!isset($_ENV['TELEGRAM_API_ID'], $_ENV['TELEGRAM_API_HASH'], $_ENV['TELEGRAM_CHANNEL'])) {
    $io->error('TELEGRAM_CHANNEL, TELEGRAM_API_ID and TELEGRAM_API_HASH env are not set');
    throw new RuntimeException('TELEGRAM_CHANNEL, TELEGRAM_API_ID and TELEGRAM_API_HASH env are not set');
}


$limit = 100;
$lastMessageId = 0;

$client = new TelegramClient(
    $_ENV['TELEGRAM_API_ID'], 
    $_ENV['TELEGRAM_API_HASH']);
$client->start();

$MadelineProto = $client->getMadeline();

$downloadPath = __DIR__ . '/../downloads/';

$fileSystem = new Filesystem();
if (!$fileSystem->exists($downloadPath)) {
    $io->comment("Creating download directory: $downloadPath");
    $fileSystem->mkdir($downloadPath);
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
                    $io->info("Downloaded: $file");
                } catch (Exception $e) {
                    $io->error("Download error: $e");
                }
            }
        }
    }

} while (count($messages['messages']) >= $limit);