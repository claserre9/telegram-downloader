<?php

namespace claserre9;

use Symfony\Component\Filesystem\Filesystem;

final class TelegramSessionPath
{
    /** Cookie that links a browser to its Telegram session across PHP session expiry and browser restarts. */
    public const COOKIE = 'tg_session';

    /** How long the browser keeps the link to its Telegram session, refreshed on every request. */
    public const COOKIE_LIFETIME = 30 * 24 * 60 * 60;

    private const ID_PATTERN = '/\A[a-f0-9]{64}\z/';

    /**
     * Select a MadelineProto session file for the current PHP session.
     *
     * @param array<string, mixed> $session
     */
    public static function forSession(array &$session, string $sessionsDir): string
    {
        if (self::isLegacy($session)) {
            return rtrim($sessionsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'web.session';
        }

        $sessionId = self::idFrom($session);
        if ($sessionId === null) {
            $sessionId = bin2hex(random_bytes(32));
            $session['telegram_session_id'] = $sessionId;
        }

        return rtrim($sessionsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'web-' . $sessionId . '.session';
    }

    /**
     * Reattach a fresh PHP session to the Telegram session named by the browser cookie.
     *
     * Marks the PHP session as restored so the controller can check whether that
     * Telegram session is still authorized before trusting it.
     *
     * @param array<string, mixed> $session
     */
    public static function restoreFromCookie(array &$session, mixed $cookieValue): void
    {
        if (self::isLegacy($session) || self::idFrom($session) !== null) {
            return;
        }
        if (!is_string($cookieValue) || preg_match(self::ID_PATTERN, $cookieValue) !== 1) {
            return;
        }

        $session['telegram_session_id'] = $cookieValue;
        $session['telegram_session_restored'] = true;
    }

    /**
     * The valid Telegram session identifier stored in the PHP session, if any.
     *
     * @param array<string, mixed> $session
     */
    public static function idFrom(array $session): ?string
    {
        $sessionId = $session['telegram_session_id'] ?? null;

        return is_string($sessionId) && preg_match(self::ID_PATTERN, $sessionId) === 1 ? $sessionId : null;
    }

    /**
     * Build the Set-Cookie header that keeps (or, with null, forgets) the browser's Telegram session link.
     */
    public static function cookieHeader(?string $sessionId, bool $secure): string
    {
        $maxAge = $sessionId === null ? 0 : self::COOKIE_LIFETIME;
        $header = self::COOKIE . '=' . ($sessionId ?? '')
            . '; Max-Age=' . $maxAge
            . '; Expires=' . gmdate('D, d M Y H:i:s \G\M\T', time() + $maxAge - ($sessionId === null ? 3600 : 0))
            . '; Path=/; HttpOnly; SameSite=Lax';

        return $secure ? $header . '; Secure' : $header;
    }

    /**
     * Delete a MadelineProto session directory created by this application.
     *
     * The session worker may still save its state while shutting down after a logout,
     * so the removal is repeated until the directory stays gone.
     */
    public static function removeSessionDirectory(string $sessionPath, float $settleSeconds = 1.5, float $timeoutSeconds = 10.0): void
    {
        $name = basename($sessionPath);
        if ($name !== 'web.session' && preg_match('/\Aweb-[a-f0-9]{64}\.session\z/', $name) !== 1) {
            throw new \InvalidArgumentException('Refusing to delete a path that is not a Telegram web session.');
        }

        $filesystem = new Filesystem();
        $deadline = microtime(true) + $timeoutSeconds;
        $goneSince = null;
        while (microtime(true) < $deadline) {
            if (file_exists($sessionPath)) {
                $filesystem->remove($sessionPath);
                $goneSince = null;
            } else {
                $goneSince ??= microtime(true);
                if (microtime(true) - $goneSince >= $settleSeconds) {
                    return;
                }
            }
            usleep(100_000);
        }
    }

    /** @param array<string, mixed> $session */
    private static function isLegacy(array $session): bool
    {
        return ($session['logged_in'] ?? false) === true && !array_key_exists('telegram_session_id', $session);
    }
}
