<?php

declare(strict_types=1);

use claserre9\WebTelegramClient;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!class_exists(SimulatedWebTelegramClient::class, false)) {
    class SimulatedWebTelegramClient extends WebTelegramClient
    {
        public function __construct(private readonly string $sessionPath)
        {
        }

        public function requestCode(string $phone): void
        {
            if (basename($this->sessionPath) === 'web.session') {
                throw new RuntimeException('This instance of MadelineProto is already logged in!');
            }

            $_SESSION['simulated_telegram_session_path'] = $this->sessionPath;
            $_SESSION['simulated_phone'] = $phone;
        }

        public function completeCode(string $code, ?string $password = null): void
        {
            if (
                ($_SESSION['simulated_telegram_session_path'] ?? null) !== $this->sessionPath
                || !isset($_SESSION['simulated_phone'])
            ) {
                throw new RuntimeException('The Telegram session changed between requesting and validating the code.');
            }
            if ($code !== '12345') {
                throw new RuntimeException('Invalid simulated Telegram code.');
            }

            $_SESSION['simulated_authenticated_path'] = $this->sessionPath;
        }

        public function getConversations(): array
        {
            $isLegacySession = basename($this->sessionPath) === 'web.session'
                && ($_SESSION['logged_in'] ?? false) === true;
            if (!$isLegacySession && ($_SESSION['simulated_authenticated_path'] ?? null) !== $this->sessionPath) {
                throw new RuntimeException('The simulated Telegram session is not authenticated.');
            }

            $_SESSION['simulated_telegram_session_path'] = $this->sessionPath;

            return [];
        }
    }
}

$GLOBALS['telegramWebClientConstructor'] = static function (int $apiId, string $apiHash, string $sessionPath): WebTelegramClient {
    return new SimulatedWebTelegramClient($sessionPath);
};

require dirname(__DIR__, 2) . '/public/index.php';