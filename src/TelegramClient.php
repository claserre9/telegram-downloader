<?php

namespace claserre9;

use danog\MadelineProto\API;
use danog\MadelineProto\Exception;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\SettingsAbstract;
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

    /**
     * @param int $telegramApiId
     * @param string $telegramApiHash
     * @param string|null $sessionPath
     */
    public function __construct(int $telegramApiId, string $telegramApiHash, ?string $sessionPath = 'session.madeline')
    {
        if (empty($telegramApiId) || empty($telegramApiHash)) {
            throw new InvalidArgumentException('Invalid API credentials provided.');
        }

        $this->telegramApiId = $telegramApiId;
        $this->telegramApiHash = $telegramApiHash;

        $this->setClientSettings();
        $this->init($this->settings, $sessionPath);
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
     * Starts the Madeline instance.
     *
     * @return void
     * @throws RuntimeException If the Madeline instance is not initialized or fails to start.
     */
    public function start(): void
    {
        if (!$this->madeline) {
            throw new RuntimeException('Madeline not initialized.');
        }

        try {
            $this->madeline->start();
        } catch (Throwable $e) {
            throw new RuntimeException('Failed to start Madeline: ' . $e->getMessage(), 0, $e);
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
}