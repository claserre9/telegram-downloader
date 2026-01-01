<?php

namespace claserre9;

use RuntimeException;
use Throwable;

class WebTelegramClient extends TelegramClient
{
    /**
     * @var bool Whether the Madeline instance has been started.
     */
    private bool $started = false;

    /**
     * Ensure the underlying Madeline instance is started once before any post-auth call.
     *
     * @return void
     * @throws RuntimeException If Madeline is not initialized.
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
     *
     * @param string $phone The phone number in international format.
     * @return void
     * @throws \InvalidArgumentException If the phone number is invalid.
     * @throws RuntimeException If Madeline is not initialized.
     * @throws Throwable If the request fails.
     */
    public function requestCode(string $phone): void
    {
        $phone = trim($phone);
        if (!preg_match('/^\+?[1-9]\d{1,14}$/', $phone)) {
            throw new \InvalidArgumentException('Invalid phone number format.');
        }

        $madeline = $this->getMadeline();
        if (!$madeline) {
            throw new RuntimeException('Madeline not initialized');
        }
        $madeline->phoneLogin($phone);
    }

    /**
     * Complete the phone login with the received code and optional password.
     *
     * @param string $code The verification code received via SMS/Telegram.
     * @param string|null $password The 2FA password, if enabled.
     * @return void
     * @throws \InvalidArgumentException If the code is invalid.
     * @throws RuntimeException If Madeline is not initialized or if 2FA password is required but not provided.
     * @throws Throwable If the login fails.
     */
    public function completeCode(string $code, ?string $password = null): void
    {
        $code = trim($code);
        if (empty($code)) {
            throw new \InvalidArgumentException('Code cannot be empty.');
        }

        $madeline = $this->getMadeline();
        if (!$madeline) {
            throw new RuntimeException('Madeline not initialized');
        }
        /** @var array{_: string} $authorization */
        $authorization = $madeline->completePhoneLogin($code);
        if ($authorization['_'] === 'account.password') {
            if ($password === null || trim($password) === '') {
                throw new RuntimeException('2FA password required');
            }
            $madeline->complete2faLogin($password);
        }

        // Finish startup after successful login to avoid interactive prompts on subsequent requests.
        $this->ensureStarted();
    }

    /**
     * Fetch a list of messages from the given chat, optionally filtered by media type.
     *
     * @param string $chat The chat ID, username, or phone number.
     * @param string $type The media type filter (photo, video, document, audio).
     * @param int $offsetId The message ID from which to start fetching.
     * @param int $limit The maximum number of messages to fetch.
     * @return array<int, array<string, mixed>> The list of messages.
     * @throws \InvalidArgumentException If inputs are invalid.
     * @throws RuntimeException If Madeline is not initialized.
     * @throws Throwable If fetching fails.
     */
    public function getMedia(string $chat, string $type = '', int $offsetId = 0, int $limit = 20): array
    {
        $chat = trim($chat);
        if (empty($chat)) {
            throw new \InvalidArgumentException('Chat identifier cannot be empty.');
        }

        if ($limit <= 0 || $limit > 100) {
            $limit = 20;
        }

        $madeline = $this->getMadeline();
        if (!$madeline) {
            throw new RuntimeException('Madeline not initialized');
        }
        $this->ensureStarted();

        // Resolve peer for reliability on private/supergroup IDs.
        $madeline->getPwrChat($chat);

        $filter = $this->mapTypeToFilter($type);
        if ($filter !== null) {
            /** @var array{messages: array<int, array<string, mixed>>} $result */
            /** @phpstan-ignore-next-line */
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
            ]);
        } else {
            /** @var array{messages: array<int, array<string, mixed>>} $result */
            $result = $madeline->messages->getHistory([
                'peer' => $chat,
                'offset_id' => $offsetId,
                'limit' => $limit,
            ]);
        }

        return $result['messages'];
    }

    /**
     * Map UI media type to Telegram search filter.
     *
     * @param string $type The media type string.
     * @return array{_: string}|null The Telegram search filter array or null.
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
     * Filters a list of messages by media type.
     *
     * @param array<int, array<string, mixed>> $messages The list of messages.
     * @param string $type The media type filter (photo, video, document, audio).
     * @return array<int, array<string, mixed>> The filtered list of messages.
     */
    public function filterMedia(array $messages, string $type): array
    {
        if ($type === '') {
            return $messages;
        }

        $filtered = [];
        foreach ($messages as $message) {
            if (!isset($message['media'])) {
                continue;
            }

            $mediaType = $message['media']['_'] ?? '';
            if ($type === 'photo' && $mediaType === 'messageMediaPhoto') {
                $filtered[] = $message;
            } elseif ($type === 'document' && $mediaType === 'messageMediaDocument') {
                $filtered[] = $message;
            } elseif ($type === 'video' && $mediaType === 'messageMediaDocument' && isset($message['media']['document']['mime_type']) && str_starts_with($message['media']['document']['mime_type'], 'video')) {
                $filtered[] = $message;
            } elseif ($type === 'audio' && $mediaType === 'messageMediaDocument' && isset($message['media']['document']['mime_type']) && str_starts_with($message['media']['document']['mime_type'], 'audio')) {
                $filtered[] = $message;
            }
        }

        return $filtered;
    }

    /**
     * Formats a byte size into a human-readable string.
     *
     * @param int $bytes The number of bytes.
     * @param int $precision The number of decimal places.
     * @return string The formatted size.
     */
    public function formatSize(int $bytes, int $precision = 2): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Download the media of a specific message to the browser.
     *
     * @param string $chat The chat ID, username, or phone number.
     * @param int $messageId The ID of the message containing the media.
     * @return void
     * @throws \InvalidArgumentException If inputs are invalid.
     * @throws RuntimeException If Madeline is not initialized or if the message has no media.
     * @throws Throwable If download fails.
     */
    public function downloadToBrowser(string $chat, int $messageId): void
    {
        $chat = trim($chat);
        if (empty($chat)) {
            throw new \InvalidArgumentException('Chat identifier cannot be empty.');
        }
        if ($messageId <= 0) {
            throw new \InvalidArgumentException('Invalid message ID.');
        }

        $madeline = $this->getMadeline();
        if (!$madeline) {
            throw new RuntimeException('Madeline not initialized');
        }
        $this->ensureStarted();
        
        $chatInfo = $madeline->getPwrChat($chat);
        $message = null;
        
        if (isset($chatInfo['type']) && ($chatInfo['type'] === 'channel' || $chatInfo['type'] === 'supergroup')) {
            /** @var array{messages: array<int, array<string, mixed>>} $res */
            /** @phpstan-ignore-next-line */
            $res = $madeline->channels->getMessages([
                'channel' => $chat,
                'id' => [$messageId],
            ]);
        } else {
            /** @var array{messages: array<int, array<string, mixed>>} $res */
            /** @phpstan-ignore-next-line */
            $res = $madeline->messages->getMessages([
                'id' => [$messageId],
            ]);
        }

        foreach ($res['messages'] as $msg) {
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
     *
     * @param string $chat The chat ID, username, or phone number.
     * @param int $messageId The ID of the message containing the media.
     * @param string $scriptUrl The URL of the script that will handle the download.
     * @return string The generated download link.
     * @throws RuntimeException If Madeline is not initialized or if the message has no media.
     * @throws Throwable If the link cannot be generated.
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
            /** @var array{messages: array<int, array<string, mixed>>} $res */
            /** @phpstan-ignore-next-line */
            $res = $madeline->channels->getMessages([
                'channel' => $chat,
                'id' => [$messageId],
            ]);
        } else {
            /** @var array{messages: array<int, array<string, mixed>>} $res */
            /** @phpstan-ignore-next-line */
            $res = $madeline->messages->getMessages([
                'id' => [$messageId],
            ]);
        }

        foreach ($res['messages'] as $msg) {
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
     * Download the media of a specific message to a directory.
     *
     * @param string $chat The chat ID, username, or phone number.
     * @param int $messageId The ID of the message containing the media.
     * @param string|null $downloadDir The directory where the file should be saved.
     * @return string The path to the downloaded file.
     * @throws \InvalidArgumentException If inputs are invalid.
     * @throws RuntimeException If Madeline is not initialized, message has no media, or download fails.
     */
    public function downloadMedia(string $chat, int $messageId, ?string $downloadDir = null): string
    {
        $chat = trim($chat);
        if (empty($chat)) {
            throw new \InvalidArgumentException('Chat identifier cannot be empty.');
        }
        if ($messageId <= 0) {
            throw new \InvalidArgumentException('Invalid message ID.');
        }

        $madeline = $this->getMadeline();
        if (!$madeline) {
            throw new RuntimeException('Madeline not initialized');
        }
        $this->ensureStarted();
        
        $chatInfo = $madeline->getPwrChat($chat);
        $message = null;
        
        if (isset($chatInfo['type']) && ($chatInfo['type'] === 'channel' || $chatInfo['type'] === 'supergroup')) {
            /** @var array{messages: array<int, array<string, mixed>>} $res */
            /** @phpstan-ignore-next-line */
            $res = $madeline->channels->getMessages([
                'channel' => $chat,
                'id' => [$messageId],
            ]);
        } else {
            /** @var array{messages: array<int, array<string, mixed>>} $res */
            /** @phpstan-ignore-next-line */
            $res = $madeline->messages->getMessages([
                'id' => [$messageId],
            ]);
        }

        foreach ($res['messages'] as $msg) {
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
            return (string)$madeline->downloadToDir($message, $downloadDir);
        } catch (Throwable $e) {
            throw new RuntimeException('Download failed: ' . $e->getMessage());
        }
    }
}
