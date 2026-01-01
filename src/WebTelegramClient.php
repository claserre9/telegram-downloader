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
        $message = null;
        
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

        foreach ($res['messages'] ?? [] as $msg) {
            if (($msg['_'] ?? '') !== 'messageEmpty' && isset($msg['media'])) {
                $message = $msg;
                break;
            }
        }

        if (!$message) {
            throw new RuntimeException('Message has no media payload');
        }

        $mime = null;
        $name = null;
        $size = null;

        if (isset($message['media']['document'])) {
            $doc = $message['media']['document'];
            $mime = $doc['mime_type'] ?? null;
            $size = $doc['size'] ?? null;
            foreach ($doc['attributes'] ?? [] as $attr) {
                if ($attr['_'] === 'documentAttributeFilename') {
                    $name = $attr['file_name'];
                    break;
                }
            }
        } elseif (isset($message['media']['photo'])) {
            $mime = 'image/jpeg'; // Photos are usually JPEGs in Telegram
            $name = 'photo_' . $message['id'] . '.jpg';
        }

        $madeline->downloadToBrowser($message, null, $size, $name, $mime);
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
        $message = null;
        
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

        foreach ($res['messages'] ?? [] as $msg) {
            if (($msg['_'] ?? '') !== 'messageEmpty' && isset($msg['media'])) {
                $message = $msg;
                break;
            }
        }

        if (!$message) {
            throw new RuntimeException('Message has no media payload');
        }

        return $madeline->getDownloadLink($message, $scriptUrl);
    }

    /**
     * Download the media of a specific message to a temporary file.
     */
    public function downloadMedia(string $chat, int $messageId, ?string $downloadDir = null): string
    {
        $madeline = $this->getMadeline();
        if (!$madeline) {
            throw new RuntimeException('Madeline not initialized');
        }
        $this->ensureStarted();
        
        $chatInfo = $madeline->getPwrChat($chat);
        $message = null;
        
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

        foreach ($res['messages'] ?? [] as $msg) {
            if (($msg['_'] ?? '') !== 'messageEmpty' && isset($msg['media'])) {
                $message = $msg;
                break;
            }
        }

        if (!$message) {
            throw new RuntimeException('Message has no media payload');
        }

        if ($downloadDir === null) {
            $downloadDir = sys_get_temp_dir();
        }

        try {
            return $madeline->downloadToDir($message, $downloadDir);
        } catch (Throwable $e) {
            throw new RuntimeException('Download failed: ' . $e->getMessage());
        }
    }
}
