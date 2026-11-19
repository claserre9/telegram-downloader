<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use claserre9\TelegramSessionPath;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$sessionsDir = '/project/sessions';
$legacySession = ['logged_in' => true];
$legacyPath = TelegramSessionPath::forSession($legacySession, $sessionsDir);
$assert($legacyPath === $sessionsDir . '/web.session', 'An existing authenticated PHP session should retain the legacy MadelineProto session.');
$assert(!isset($legacySession['telegram_session_id']), 'Legacy sessions should not be migrated or changed.');

$browserA = [];
$browserAPath = TelegramSessionPath::forSession($browserA, $sessionsDir);
$assert(preg_match('/\/web-[a-f0-9]{64}\.session\z/', $browserAPath) === 1, 'A new browser should receive a random, filesystem-safe MadelineProto session path.');
$assert(isset($browserA['telegram_session_id']), 'The Telegram session identifier should be stored in the PHP session.');
$assert(TelegramSessionPath::forSession($browserA, $sessionsDir) === $browserAPath, 'A browser should reuse its Telegram session on subsequent requests.');

$browserB = [];
$browserBPath = TelegramSessionPath::forSession($browserB, $sessionsDir);
$assert($browserBPath !== $browserAPath, 'Different browsers should not share a Telegram session file.');

$loggedOutBrowser = [];
$loggedOutPath = TelegramSessionPath::forSession($loggedOutBrowser, $sessionsDir);
$assert($loggedOutPath !== $browserAPath, 'A fresh PHP session after logout should not reuse the previous browser session.');

$invalidSession = ['telegram_session_id' => '../web.session'];
$invalidPath = TelegramSessionPath::forSession($invalidSession, $sessionsDir);
$assert(preg_match('/\/web-[a-f0-9]{64}\.session\z/', $invalidPath) === 1, 'Invalid session identifiers should be replaced before building a path.');

$validId = str_repeat('b', 64);
$expired = [];
TelegramSessionPath::restoreFromCookie($expired, $validId);
$assert(($expired['telegram_session_id'] ?? null) === $validId && ($expired['telegram_session_restored'] ?? false) === true, 'A fresh PHP session should be relinked to the Telegram session named by the cookie.');
$assert(TelegramSessionPath::forSession($expired, $sessionsDir) === $sessionsDir . '/web-' . $validId . '.session', 'A restored PHP session should reuse the cookie Telegram session.');

$current = ['telegram_session_id' => str_repeat('c', 64)];
TelegramSessionPath::restoreFromCookie($current, $validId);
$assert($current['telegram_session_id'] === str_repeat('c', 64) && !isset($current['telegram_session_restored']), 'A live PHP session must not be overridden by the cookie.');

foreach (['../web.session', '', null, ['x'], str_repeat('Z', 64)] as $forged) {
    $forgedSession = [];
    TelegramSessionPath::restoreFromCookie($forgedSession, $forged);
    $assert($forgedSession === [], 'Invalid cookie values must be ignored.');
}
$legacyCookie = ['logged_in' => true];
TelegramSessionPath::restoreFromCookie($legacyCookie, $validId);
$assert($legacyCookie === ['logged_in' => true], 'Legacy sessions should not be changed by the cookie.');

$keep = TelegramSessionPath::cookieHeader($validId, false);
$assert(str_starts_with($keep, 'tg_session=' . $validId . ';') && str_contains($keep, 'Max-Age=2592000') && str_contains($keep, 'HttpOnly') && !str_contains($keep, 'Secure'), 'The link cookie should last 30 days and be HttpOnly.');
$assert(str_ends_with(TelegramSessionPath::cookieHeader($validId, true), '; Secure'), 'The link cookie should be Secure over HTTPS.');
$assert(str_contains(TelegramSessionPath::cookieHeader(null, false), 'Max-Age=0'), 'A null identifier should expire the link cookie.');

$tempRoot = sys_get_temp_dir() . '/tg-session-path-test-' . bin2hex(random_bytes(4));
mkdir($tempRoot);
try {
    $sessionDir = $tempRoot . '/web-' . $validId . '.session';
    mkdir($sessionDir . '/ipc', 0777, true);
    file_put_contents($sessionDir . '/safe.php', 'state');
    TelegramSessionPath::removeSessionDirectory($sessionDir, 0.2, 2.0);
    $assert(!file_exists($sessionDir), 'A web session directory should be deleted.');

    foreach ([$tempRoot, $tempRoot . '/other', $tempRoot . '/web-' . $validId . '.session/..'] as $unsafe) {
        $refused = false;
        try {
            TelegramSessionPath::removeSessionDirectory($unsafe, 0.2, 2.0);
        } catch (InvalidArgumentException) {
            $refused = true;
        }
        $assert($refused, 'Paths that are not web session directories must never be deleted.');
    }
    $assert(is_dir($tempRoot), 'A refused deletion must leave the directory in place.');
} finally {
    (new Symfony\Component\Filesystem\Filesystem())->remove($tempRoot);
}

fwrite(STDOUT, "Telegram session path tests passed.\n");