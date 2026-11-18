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

fwrite(STDOUT, "Telegram session path tests passed.\n");