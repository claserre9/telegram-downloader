<?php

namespace claserre9;

use Closure;
use danog\MadelineProto\API;
use danog\MadelineProto\Exception;
use danog\MadelineProto\Ipc\Client;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\SettingsAbstract;
use danog\MadelineProto\TL\Types\LoginQrCode;
use danog\MadelineProto\Tools;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Class TelegramClient
 *
 * A client implementation for interacting with the Telegram API using MadelineProto.
 */
class TelegramClient
{
    private readonly int $telegramApiId;
    private readonly string $telegramApiHash;
    private ?API $madeline = null;
    private ?Settings $settings = null;
    private readonly string $sessionPath;

    /**
     * @param int $telegramApiId The Telegram API ID.
     * @param string $telegramApiHash The Telegram API Hash.
     * @param string|null $sessionPath The path to the session file.
     * @throws InvalidArgumentException If API credentials are empty.
     */
    public function __construct(int $telegramApiId, string $telegramApiHash, ?string $sessionPath = 'session.madeline')
    {
        if ($telegramApiId <= 0) {
            throw new InvalidArgumentException('Invalid API ID provided.');
        }
        if (empty($telegramApiHash)) {
            throw new InvalidArgumentException('Invalid API Hash provided.');
        }

        $this->telegramApiId = $telegramApiId;
        $this->telegramApiHash = $telegramApiHash;

        $this->sessionPath = $sessionPath ?? 'session.madeline';
        $this->setClientSettings();
        $this->init($this->settings, $this->sessionPath);
    }

    /**
     * Configures and sets the client settings necessary for the application.
     *
     * @return void
     */
    private function setClientSettings(): void
    {
        $this->settings = new Settings();
        $this->settings->setAppInfo((new AppInfo())
            ->setApiId($this->telegramApiId)
            ->setApiHash($this->telegramApiHash)
        );
    }

    /**
     * Initializes the MadelineProto API with the provided settings and session path.
     *
     * @param SettingsAbstract $settings The settings configuration for the API.
     * @param string $sessionPath The path to the session file.
     * @return void
     * @throws RuntimeException If initialization of the MadelineProto API fails.
     */
    private function init(SettingsAbstract $settings, string $sessionPath): void
    {
        try {
            $this->madeline = new API($sessionPath, $settings);
        } catch (Exception $e) {
            throw new RuntimeException('Failed to initialize MadelineProto API: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Log in from the terminal by QR code only, then start the Madeline instance.
     *
     * The QR code must be scanned from an official Telegram app (Settings → Devices → Link Desktop Device).
     * Phone-number and bot-token logins are not offered.
     *
     * @param (Closure(string): void)|null $write Displays a message; defaults to standard output.
     * @param (Closure(string): string)|null $askPassword Asks for the 2FA password without echoing it.
     * @return void
     * @throws RuntimeException If the Madeline instance is not initialized or the login fails;
     *                          see TerminalQrLogin::wasInterrupted() for Ctrl+C.
     */
    public function start(?Closure $write = null, ?Closure $askPassword = null): void
    {
        $madeline = $this->madeline;
        if (!$madeline) {
            throw new RuntimeException('Madeline not initialized.');
        }

        $write ??= static function (string $message): void {
            fwrite(STDOUT, $message . PHP_EOL);
        };
        $askPassword ??= static fn (string $prompt): string => Tools::readLine($prompt . ': ', hidden: true);

        /** @var LoginQrCode|null $qrCode Shared by reference between the two QR closures. */
        $qrCode = null;
        $login = new TerminalQrLogin(
            static fn (): int => $madeline->getAuthorization(),
            $this->abandonPendingCodeLogin(...),
            static function () use ($madeline, &$qrCode): ?string {
                $qrCode = $madeline->qrLogin();
                return $qrCode?->getQRText(2);
            },
            static function () use (&$qrCode): ?string {
                $qrCode = $qrCode?->waitForLoginOrQrCodeExpiration();
                return $qrCode?->getQRText(2);
            },
            static function (string $password) use ($madeline): void {
                $madeline->complete2faLogin($password);
            },
            $write,
            $askPassword,
        );

        try {
            $login->run();
            $madeline->start();
        } catch (Throwable $e) {
            throw new RuntimeException('Failed to start Madeline: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Drop a phone-code login left pending by an older version of this app; it would block QR login.
     */
    protected function abandonPendingCodeLogin(): void
    {
        if ($this->madeline && in_array($this->madeline->getAuthorization(), [API::WAITING_CODE, API::WAITING_SIGNUP], true)) {
            Client::giveInstanceBySession($this->madeline->getSessionName())->setLoginState(API::NOT_LOGGED_IN);
        }
    }

    /**
     * Retrieves the Madeline API.
     *
     * @return API|null The Madeline API instance or null if not set.
     */
    public function getMadeline(): ?API
    {
        return $this->madeline;
    }

    /**
     * The MadelineProto session path this client was created with.
     */
    public function getSessionPath(): string
    {
        return $this->sessionPath;
    }
}
