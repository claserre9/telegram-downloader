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
}

$GLOBALS['telegramWebClientConstructor'] = static function (int $apiId, string $apiHash, string $sessionPath): WebTelegramClient {
    return new SimulatedDialogDirectoryWebTelegramClient($apiId, $apiHash, $sessionPath);
};

require dirname(__DIR__, 2) . '/public/index.php';