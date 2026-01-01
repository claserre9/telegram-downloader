<?php

namespace claserre9;

use RuntimeException;
use Throwable;

class WebTelegramClient extends TelegramClient
{
    private bool $started = false;

    /**
     * Ensure the underlying Madeline instance is started once before any post-auth call.
     */
    private function ensureStarted(): void
    {
        $madeline = $this->getMadeline();
        if (!$madeline) {
            throw new RuntimeException('Madeline not initialized');
        }

        if ($this->started) {
            return;
        }

        $madeline->start();
        $this->started = true;
    }

    /**
     * Request a login code to be sent to the user's phone.
     */
    public function requestCode(string $phone): void
    {
        $madeline = $this->getMadeline();
        if (!$madeline) {
            throw new RuntimeException('Madeline not initialized');
        }
        $madeline->phoneLogin($phone);
    }

    /**
     * Complete the phone login with the received code and optional password.
     */
    public function completeCode(string $code, ?string $password = null): void
    {
        $madeline = $this->getMadeline();
        if (!$madeline) {
            throw new RuntimeException('Madeline not initialized');
        }
        $authorization = $madeline->completePhoneLogin($code);
        if ($authorization['_'] === 'account.password') {
            if ($password === null) {
                throw new RuntimeException('2FA password required');
            }
            $madeline->complete2faLogin($password);
        }

        // Finish startup after successful login to avoid interactive prompts on subsequent requests.
        $this->ensureStarted();
    }

    /**
     * Fetch a list of messages from the given chat, optionally filtered by media type.
     */
    public function getMedia(string $chat, string $type = '', int $offsetId = 0, int $limit = 20): array
    {
        $madeline = $this->getMadeline();
        if (!$madeline) {
            throw new RuntimeException('Madeline not initialized');
        }
        $this->ensureStarted();

        // Resolve peer for reliability on private/supergroup IDs.
        $madeline->getPwrChat($chat);

        $filter = $this->mapTypeToFilter($type);
        if ($filter !== null) {
            $result = $madeline->messages->search([
                'peer' => $chat,
                'q' => '',
                'filter' => $filter,
                'min_date' => 0,
                'max_date' => 0,
                'offset_id' => $offsetId,
                'add_offset' => 0,
                'limit' => $limit,
                'max_id' => 0,
                'min_id' => 0,
                'hash' => 0,
                'timeout' => 20,
            ]);
        } else {
            $result = $madeline->messages->getHistory([
                'peer' => $chat,
                'offset_id' => $offsetId,
                'limit' => $limit,
                'timeout' => 20,
            ]);
        }

        return $result['messages'] ?? [];
    }

    /**
     * Map UI media type to Telegram search filter.
     */
    private function mapTypeToFilter(string $type): ?array
    {
        return match ($type) {
            'photo' => ['_' => 'inputMessagesFilterPhotos'],
            'video' => ['_' => 'inputMessagesFilterVideo'],
            'document' => ['_' => 'inputMessagesFilterDocument'],
            'audio' => ['_' => 'inputMessagesFilterMusic'],
            default => null,
        };
    }

    /**
     * Download the media of a specific message to the browser.
     */
    public function downloadToBrowser(string $chat, int $messageId): void
    {
        $madeline = $this->getMadeline();
        if (!$madeline) {
            throw new RuntimeException('Madeline not initialized');
        }
        $this->ensureStarted();
        $chatInfo = $madeline->getPwrChat($chat);
        if (isset($chatInfo['type']) && ($chatInfo['type'] === 'channel' || $chatInfo['type'] === 'supergroup')) {
            $res = $madeline->channels->getMessages([
                'channel' => $chat,
                'id' => [$messageId],
            ]);
        } else {
            $res = $madeline->messages->getMessages([
                'id' => [$messageId],
            ]);
        }

        foreach ($res['messages'] ?? [] as $message) {
            // Skip empty/system messages.
            if (($message['_'] ?? '') === 'messageEmpty' || !isset($message['media'])) {
                continue;
            }

            $madeline->downloadToBrowser($message);
            return;
        }

        throw new RuntimeException('Message has no media payload');
    }

    /**
     * Build a download link for a specific message using a provided download script URL.
     */
    public function getDownloadLink(string $chat, int $messageId, string $scriptUrl): string
    {
        $madeline = $this->getMadeline();
        if (!$madeline) {
            throw new RuntimeException('Madeline not initialized');
        }
        $this->ensureStarted();
        $chatInfo = $madeline->getPwrChat($chat);
        if (isset($chatInfo['type']) && ($chatInfo['type'] === 'channel' || $chatInfo['type'] === 'supergroup')) {
            $res = $madeline->channels->getMessages([
                'channel' => $chat,
                'id' => [$messageId],
            ]);
        } else {
            $res = $madeline->messages->getMessages([
                'id' => [$messageId],
            ]);
        }

        foreach ($res['messages'] ?? [] as $message) {
            if (($message['_'] ?? '') === 'messageEmpty' || !isset($message['media'])) {
                continue;
            }

            return $madeline->getDownloadLink($message, $scriptUrl);
        }

        throw new RuntimeException('Message has no media payload');
    }

    /**
     * Download the media of a specific message to a temporary file.
     */
    public function downloadMedia(string $chat, int $messageId): string
    {
        $madeline = $this->getMadeline();
        if (!$madeline) {
            throw new RuntimeException('Madeline not initialized');
        }
        $this->ensureStarted();
        $chatInfo = $madeline->getPwrChat($chat);

        if (isset($chatInfo['type']) && ($chatInfo['type'] === 'channel' || $chatInfo['type'] === 'supergroup')) {
            $res = $madeline->channels->getMessages([
                'channel' => $chat,
                'id' => [$messageId],
            ]);
        } else {
            $res = $madeline->messages->getMessages([
                'id' => [$messageId],
            ]);
        }

        foreach ($res['messages'] ?? [] as $message) {
            // Skip empty/system messages.
            if (($message['_'] ?? '') === 'messageEmpty' || !isset($message['media'])) {
                continue;
            }

            try {
                return $madeline->downloadToDir($message, sys_get_temp_dir());
            } catch (Throwable) {
                continue;
            }
        }

        throw new RuntimeException('Message has no media payload');
    }
}
