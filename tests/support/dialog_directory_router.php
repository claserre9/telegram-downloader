<?php

declare(strict_types=1);

use claserre9\WebTelegramClient;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

class SimulatedDialogDirectoryWebTelegramClient extends WebTelegramClient
{
    public function __construct(int $apiId, string $apiHash, string $sessionPath)
    {
    }

    public function getConversations(): array
    {
        if (($_SESSION['simulate_dialog_error'] ?? false) === true) {
            throw new RuntimeException('Simulated Telegram connection failure.');
        }

        return [
            ['id' => -1001234567890, 'name' => 'Reloaded channel', 'type' => 'channel', 'username' => 'reloaded_channel'],
            ['id' => 501, 'name' => 'Reloaded conversation', 'type' => 'user', 'username' => 'reloaded_user'],
        ];
    }

    public function getMedia(string $chat, string $type = '', int $offsetId = 0, int $limit = 20): array
    {
        return [
            [
                'id' => 707,
                'date' => 1700000000,
                'media' => [
                    '_' => 'messageMediaDocument',
                    'document' => [
                        'mime_type' => 'video/mp4',
                        'size' => 1048576,
                        'attributes' => [
                            ['_' => 'documentAttributeFilename', 'file_name' => '<video & clip>.mp4'],
                        ],
                    ],
                ],
            ],
            [
                'id' => 706,
                'date' => 1700000001,
                'media' => [
                    '_' => 'messageMediaPhoto',
                    'photo' => ['sizes' => [['size' => 2048]]],
                ],
            ],
        ];
    }
}

$GLOBALS['telegramWebClientConstructor'] = static function (int $apiId, string $apiHash, string $sessionPath): WebTelegramClient {
    return new SimulatedDialogDirectoryWebTelegramClient($apiId, $apiHash, $sessionPath);
};

require dirname(__DIR__, 2) . '/public/index.php';