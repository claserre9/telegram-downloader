<?php

namespace claserre9;

use RuntimeException;
use Throwable;

class WebTelegramClient extends TelegramClient
{
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
    }

    /**
     * Fetch a list of messages from the given chat.
     */
    public function getMedia(string $chat, int $offsetId = 0, int $limit = 20): array
    {
        $madeline = $this->getMadeline();
        if (!$madeline) {
            throw new RuntimeException('Madeline not initialized');
        }

        $result = $madeline->messages->getHistory([
            'peer' => $chat,
            'offset_id' => $offsetId,
            'limit' => $limit,
        ]);

        return $result['messages'] ?? [];
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
        $res = $madeline->messages->getMessages([
            'peer' => $chat,
            'id' => [$messageId],
        ]);
        if (empty($res['messages'][0]['media'])) {
            throw new RuntimeException('Message has no media');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'tg_');
        $madeline->downloadToFile($res['messages'][0]['media'], $tmp);
        return $tmp;
    }
}
