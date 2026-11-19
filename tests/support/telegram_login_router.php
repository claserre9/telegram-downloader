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

        public static function authMarker(string $sessionPath): string
        {
            return sys_get_temp_dir() . '/simulated-telegram-auth-' . md5($sessionPath);
        }

        public static function logoutMarker(string $sessionPath): string
        {
            return sys_get_temp_dir() . '/simulated-telegram-logout-' . md5($sessionPath);
        }

        public static function unreachableMarker(string $sessionPath): string
        {
            return sys_get_temp_dir() . '/simulated-telegram-unreachable-' . md5($sessionPath);
        }

        private function failIfUnreachable(): void
        {
            if (is_file(self::unreachableMarker($this->sessionPath))) {
                throw new RuntimeException('The simulated Telegram session worker is not responding.');
            }
        }

        private function markAuthenticated(): void
        {
            $_SESSION['simulated_authenticated_path'] = $this->sessionPath;
            file_put_contents(self::authMarker($this->sessionPath), '1');
        }

        public function isAuthorized(): bool
        {
            $this->failIfUnreachable();
            return is_file(self::authMarker($this->sessionPath));
        }

        public function logout(): void
        {
            if (is_file(self::authMarker($this->sessionPath))) {
                unlink(self::authMarker($this->sessionPath));
            }
            file_put_contents(self::logoutMarker($this->sessionPath), '1');
        }

        public function completePassword(string $password): void
        {
            if (!($_SESSION['simulated_password_pending'] ?? false) || $password !== 'valid-password') {
                throw new RuntimeException('Invalid simulated Telegram password.');
            }

            $this->markAuthenticated();
            unset($_SESSION['simulated_password_pending']);
        }

        public function qrLogin(): array
        {
            $_SESSION['simulated_telegram_session_path'] = $this->sessionPath;
            $_SESSION['simulated_qr_polls'] = (int) ($_SESSION['simulated_qr_polls'] ?? 0) + 1;
            $this->failIfUnreachable();
            if ($_SESSION['simulated_qr_failure'] ?? false) {
                throw new RuntimeException('Telegram refused the simulated QR login.');
            }

            $scan = $_SESSION['simulated_qr_scan'] ?? null;
            if ($scan === 'logged_in') {
                $this->markAuthenticated();
                return ['status' => 'logged_in'];
            }
            if ($scan === 'password') {
                $_SESSION['simulated_password_pending'] = true;
                return ['status' => 'password'];
            }

            return [
                'status' => 'pending',
                'svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>',
                'expires_in' => 30,
            ];
        }

        public function getConversations(): array
        {
            $isLegacySession = basename($this->sessionPath) === 'web.session'
                && ($_SESSION['logged_in'] ?? false) === true;
            if (!$isLegacySession && !$this->isAuthorized()) {
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