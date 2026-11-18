<?php

namespace claserre9;

final class TelegramSessionPath
{
    /**
     * Select a MadelineProto session file for the current PHP session.
     *
     * @param array<string, mixed> $session
     */
    public static function forSession(array &$session, string $sessionsDir): string
    {
        if (($session['logged_in'] ?? false) === true && !array_key_exists('telegram_session_id', $session)) {
            return rtrim($sessionsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'web.session';
        }

        $sessionId = $session['telegram_session_id'] ?? null;
        if (!is_string($sessionId) || preg_match('/\A[a-f0-9]{64}\z/', $sessionId) !== 1) {
            $sessionId = bin2hex(random_bytes(32));
            $session['telegram_session_id'] = $sessionId;
        }

        return rtrim($sessionsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'web-' . $sessionId . '.session';
    }
}