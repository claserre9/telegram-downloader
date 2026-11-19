<?php

namespace claserre9;

use danog\MadelineProto\API;
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
     * Complete two-step verification after the QR code was scanned.
     *
     * @throws RuntimeException If Madeline is not initialized.
     * @throws Throwable If the password is rejected or login fails.
     */
    public function completePassword(#[\SensitiveParameter] string $password): void
    {
        $madeline = $this->getMadeline();
        if (!$madeline) {
            throw new RuntimeException('Madeline not initialized');
        }

        $madeline->complete2faLogin($password);
        if ($madeline->getAuthorization() !== API::LOGGED_IN) {
            throw new RuntimeException('Telegram authorization was not finalized.');
        }
        $this->ensureStarted();
    }

    /**
     * Start or poll a QR-code login.
     *
     * The QR code must be scanned from an official Telegram app (Settings → Devices → Link Desktop Device).
     * Telegram confirms the scan asynchronously, so callers poll this method until it stops returning "pending".
     *
     * @return array{status: 'pending', svg: string, expires_in: int}|array{status: 'logged_in'}|array{status: 'password'}
     * @throws RuntimeException If Madeline is not initialized or the login ends in an unsupported state.
     * @throws Throwable If Telegram rejects the request.
     */
    public function qrLogin(): array
    {
        $madeline = $this->getMadeline();
        if (!$madeline) {
            throw new RuntimeException('Madeline not initialized');
        }

        $this->abandonPendingCodeLogin();
        $qrCode = $madeline->getAuthorization() === API::NOT_LOGGED_IN ? $madeline->qrLogin() : null;
        if ($qrCode !== null) {
            return [
                'status' => 'pending',
                'svg' => $qrCode->getQRSvg(320, 2),
                'expires_in' => $qrCode->expiresIn(),
            ];
        }

        $state = $madeline->getAuthorization();
        if ($state === API::LOGGED_IN) {
            $this->ensureStarted();
            return ['status' => 'logged_in'];
        }
        if ($state === API::WAITING_PASSWORD) {
            return ['status' => 'password'];
        }

        throw new RuntimeException('Telegram did not return a QR login code.');
    }

    /**
     * Whether this browser's Telegram session is currently authorized.
     */
    public function isAuthorized(): bool
    {
        $madeline = $this->getMadeline();

        return $madeline !== null && $madeline->getAuthorization() === API::LOGGED_IN;
    }

    /**
     * Log out of Telegram and delete this browser's session files.
     *
     * The local files are removed even if Telegram cannot be reached, so the
     * session cannot be reused; the error is rethrown so the caller can report it.
     *
     * @throws Throwable If Telegram rejects or cannot process the logout.
     */
    public function logout(): void
    {
        $madeline = $this->getMadeline();
        try {
            // Revokes the authorization on Telegram's side and stops this session's worker process.
            $madeline?->logout();
        } finally {
            TelegramSessionPath::removeSessionDirectory($this->getSessionPath());
        }
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

        // Resolve the peer from MadelineProto's local cache. getPwrChat would also fetch the full
        // member list and is rate-limited by Telegram (waits of 30 s after a few calls).
        $madeline->getInfo($chat);

        $filter = MediaType::searchFilter($type);
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
     * Retrieve all conversations from the main and archived Telegram folders.
     *
     * @return list<array{id: int|string, name: string, type: string, username: string}>
     * @throws RuntimeException If Madeline is not initialized or pagination cannot advance.
     * @throws Throwable If fetching dialogs fails.
     */
    public function getConversations(): array
    {
        $madeline = $this->getMadeline();
        if (!$madeline) {
            throw new RuntimeException('Madeline not initialized');
        }
        $this->ensureStarted();

        return DialogDirectory::fetchAll(
            static function (int $folderId, int $offsetDate, int $offsetId, int|array|string $offsetPeer, int $limit) use ($madeline): array {
                /** @var array<string, mixed> $result */
                $result = $madeline->messages->getDialogs(
                    exclude_pinned: $offsetId !== 0,
                    folder_id: $folderId,
                    offset_date: $offsetDate,
                    offset_id: $offsetId,
                    offset_peer: $offsetPeer,
                    limit: $limit,
                    hash: []
                );

                return $result;
            },
            static fn(mixed $peer): array => $madeline->getInfo($peer),
            100,
            static fn(array $message): int => $madeline->getInfo($message, \danog\MadelineProto\API::INFO_TYPE_ID)
        );
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
        return FileSize::format($bytes, $precision);
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
        
        $chatInfo = $madeline->getInfo($chat);
        $message = null;
        
        if (in_array($chatInfo['type'], ['channel', 'supergroup'], true)) {
            /** @var array{messages: array<int, array<string, mixed>>} $res */
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

        $name = null;
        $size = null;

        if (isset($message['media']['document'])) {
            $doc = $message['media']['document'];
            $size = $doc['size'] ?? null;
            foreach ($doc['attributes'] ?? [] as $attr) {
                if ($attr['_'] === 'documentAttributeFilename') {
                    $name = $attr['file_name'];
                    break;
                }
            }
        } elseif (isset($message['media']['photo'])) {
            $name = 'photo_' . $message['id'] . '.jpg';
        }

        // An octet-stream MIME type makes MadelineProto use Content-Disposition: attachment.
        $madeline->downloadToBrowser($message, null, $size, $name, 'application/octet-stream');
    }
}
