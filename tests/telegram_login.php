<?php

declare(strict_types=1);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$request = static function (int $port, string $path, string $method = 'GET', array $form = [], ?string $cookie = null): array {
    $headers = [];
    if ($cookie !== null) {
        $headers[] = 'Cookie: ' . $cookie;
    }
    $content = '';
    if ($method === 'POST') {
        $content = http_build_query($form);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
            'timeout' => 5,
        ],
    ]);
    $body = file_get_contents('http://127.0.0.1:' . $port . $path, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    $parsedHeaders = [];

    if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $matches)) {
        $status = (int) $matches[1];
    }
    foreach (array_slice($responseHeaders, 1) as $header) {
        if (str_contains($header, ':')) {
            [$name, $value] = explode(':', $header, 2);
            $name = strtolower(trim($name));
            $parsedHeaders[$name] = isset($parsedHeaders[$name]) && $name === 'set-cookie'
                ? $parsedHeaders[$name] . "\n" . trim($value)
                : trim($value);
        }
    }

    return [$status, $parsedHeaders, $body === false ? '' : $body];
};

$cookieFrom = static function (array $headers): ?string {
    $setCookie = $headers['set-cookie'] ?? '';
    if (preg_match('/\b' . preg_quote(session_name(), '/') . '=([^;]+)/', $setCookie, $matches) !== 1) {
        return null;
    }

    return session_name() . '=' . $matches[1];
};

$telegramCookieFrom = static function (array $headers): ?string {
    if (preg_match('/^tg_session=([^;]*);[^\n]*/m', $headers['set-cookie'] ?? '', $matches) !== 1) {
        return null;
    }

    return $matches[0];
};

$sessionIdFrom = static function (string $cookie): string {
    $prefix = session_name() . '=';
    return str_starts_with($cookie, $prefix) ? substr($cookie, strlen($prefix)) : '';
};

$readSession = static function (string $sessionId): array {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    session_id($sessionId);
    if (!session_start()) {
        throw new RuntimeException('A test PHP session should start.');
    }
    $session = $_SESSION;
    session_abort();

    return $session;
};

$updateSession = static function (string $sessionId, array $values): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    session_id($sessionId);
    if (!session_start()) {
        throw new RuntimeException('A test PHP session should start for updates.');
    }
    $_SESSION = array_merge($_SESSION, $values);
    session_write_close();
};

$sessionSavePath = session_save_path();
$sessionPathParts = explode(';', is_string($sessionSavePath) ? $sessionSavePath : '');
$sessionDirectory = end($sessionPathParts);
if (!is_string($sessionDirectory) || $sessionDirectory === '') {
    throw new RuntimeException('The PHP session directory should be available for the HTTP test.');
}

$projectRoot = dirname(__DIR__);
$legacySessionId = 'legacy' . bin2hex(random_bytes(16));
$waitingSessionId = 'waiting' . bin2hex(random_bytes(16));
$sessionIds = [$legacySessionId, $waitingSessionId];
session_id($legacySessionId);
if (!session_start()) {
    throw new RuntimeException('The legacy test PHP session should start.');
}
$_SESSION = ['logged_in' => true];
session_write_close();
session_id($waitingSessionId);
if (!session_start()) {
    throw new RuntimeException('The waiting-code test PHP session should start.');
}
$waitingTelegramId = bin2hex(random_bytes(32));
$_SESSION = [
    // A phone-code login left pending by an older version of the app.
    'step' => 'code',
    'phone' => '+12025550125',
    'login_phone_code_hash' => 'old-secret-hash',
    'telegram_session_id' => $waitingTelegramId,
];
session_write_close();

$socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if (!is_resource($socket)) {
    throw new RuntimeException('An available local port should be reserved.');
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$port = (int) substr((string) strrchr((string) $address, ':'), 1);

$serverEnvironment = getenv();
if (!is_array($serverEnvironment)) {
    $serverEnvironment = [];
}
$serverEnvironment['TELEGRAM_API_ID'] = '12345';
$serverEnvironment['TELEGRAM_API_HASH'] = 'simulated-api-hash';
$server = proc_open(
    [
        PHP_BINARY,
        '-d',
        'variables_order=EGPCS',
        '-S',
        '127.0.0.1:' . $port,
        '-t',
        $projectRoot . '/public',
        $projectRoot . '/tests/support/telegram_login_router.php',
    ],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
    $serverPipes,
    null,
    $serverEnvironment
);
if (!is_resource($server)) {
    throw new RuntimeException('The Telegram login test server should start.');
}

try {
    $serverReady = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $connection = @fsockopen('127.0.0.1', $port, $connectionErrorCode, $connectionErrorMessage, 0.1);
        if (is_resource($connection)) {
            fclose($connection);
            $serverReady = true;
            break;
        }
        usleep(50000);
    }
    $assert($serverReady, 'The Telegram login test server should accept requests.');

    [$legacyStatus] = $request($port, '/', 'GET', [], session_name() . '=' . $legacySessionId);
    $assert($legacyStatus === 200, 'A PHP session already authenticated before the change should keep working.');
    $legacySnapshot = $readSession($legacySessionId);
    $assert(
        ($legacySnapshot['simulated_telegram_session_path'] ?? null) === $projectRoot . '/public/../sessions/web.session',
        'An existing authenticated PHP session should continue using sessions/web.session.'
    );

    [$waitingStatus, , $waitingBody] = $request($port, '/login', 'GET', [], session_name() . '=' . $waitingSessionId);
    $assert($waitingStatus === 200 && str_contains($waitingBody, 'src="data:image/svg+xml;base64,'), 'An old pending phone-code step should now show the QR code.');
    $assert(!str_contains($waitingBody, 'name="code"') && !str_contains($waitingBody, 'name="phone"'), 'Old pending phone-code steps must not show code or phone forms.');
    $assert(!str_contains($waitingBody, 'old-secret-hash'), 'An old code hash must not be exposed.');
    $updateSession($waitingSessionId, ['simulated_qr_scan' => 'logged_in']);
    [$waitingLoginStatus] = $request($port, '/login', 'GET', [], session_name() . '=' . $waitingSessionId);
    $waitingSnapshot = $readSession($waitingSessionId);
    $assert($waitingLoginStatus === 302 && ($waitingSnapshot['logged_in'] ?? false) === true, 'An old pending login should complete by QR code.');
    $assert(!isset($waitingSnapshot['login_phone_code_hash']) && !isset($waitingSnapshot['phone']) && !isset($waitingSnapshot['step']), 'Logging in should drop old phone-code data.');

    // Browser A: the login page shows the QR code right away.
    [$loginStatus, $loginHeaders, $loginBody] = $request($port, '/login');
    $assert($loginStatus === 200, 'A new browser should see the login page.');
    $assert(str_contains($loginBody, 'src="data:image/svg+xml;base64,'), 'The login page should show the QR code immediately.');
    $assert(str_contains($loginBody, 'Link Desktop Device'), 'The login page should explain where to scan the QR code.');
    $assert(str_contains($loginBody, 'http-equiv="refresh"'), 'The QR page should refresh automatically while waiting for the scan.');
    $assert(str_contains($loginBody, 'renews in 30 seconds'), 'The QR page should show when the code expires.');
    $assert(!str_contains($loginBody, '<svg'), 'The QR SVG should be embedded as an image, not as inline markup.');
    $assert(!str_contains($loginBody, 'name="phone"') && !str_contains($loginBody, 'name="code"'), 'Phone-code login must not be offered.');
    $browserACookie = $cookieFrom($loginHeaders);
    $assert($browserACookie !== null, 'The first browser should receive a PHP session cookie.');
    $browserASessionId = $sessionIdFrom($browserACookie);
    $sessionIds[] = $browserASessionId;
    $browserASession = $readSession($browserASessionId);
    $browserAPath = $browserASession['simulated_telegram_session_path'] ?? null;
    $assert(is_string($browserAPath) && str_ends_with($browserAPath, '/web-' . ($browserASession['telegram_session_id'] ?? '') . '.session'), 'QR login should use the browser-specific Telegram session.');

    foreach ([['phone' => '+12025550123'], ['code' => '12345'], ['action' => 'resend_code'], ['password' => 'valid-password']] as $form) {
        [$ignoredStatus, , $ignoredBody] = $request($port, '/login', 'POST', $form, $browserACookie);
        $assert($ignoredStatus === 200 && str_contains($ignoredBody, 'src="data:image/svg+xml;base64,'), 'Removed login forms must be ignored and the QR code shown.');
    }
    $assert(($readSession($browserASessionId)['logged_in'] ?? false) !== true, 'Posting old login forms must not log in.');

    $updateSession($browserASessionId, ['simulated_qr_failure' => true]);
    [$qrErrorStatus, , $qrErrorBody] = $request($port, '/login', 'GET', [], $browserACookie);
    $assert($qrErrorStatus === 200 && str_contains($qrErrorBody, 'QR login error: Telegram refused the simulated QR login.'), 'QR login errors should be displayed.');
    $assert(str_contains($qrErrorBody, 'http-equiv="refresh"'), 'The page should keep retrying after a QR error.');
    $updateSession($browserASessionId, ['simulated_qr_failure' => false]);

    $updateSession($browserASessionId, ['simulated_qr_scan' => 'logged_in']);
    [$qrDoneStatus, $qrDoneHeaders] = $request($port, '/login', 'GET', [], $browserACookie);
    $assert($qrDoneStatus === 302 && ($qrDoneHeaders['location'] ?? null) === '/', 'A scanned QR code should complete login and redirect home.');
    [$browserAHomeStatus, $browserAHomeHeaders] = $request($port, '/', 'GET', [], $browserACookie);
    $assert($browserAHomeStatus === 200, 'A QR-authenticated browser should reach the home page.');
    [$loggedInLoginStatus, $loggedInLoginHeaders] = $request($port, '/login', 'GET', [], $browserACookie);
    $assert($loggedInLoginStatus === 302 && ($loggedInLoginHeaders['location'] ?? null) === '/', 'A logged-in browser visiting the login page should go straight home.');

    // The browser keeps a 30-day link to its Telegram session, independent of the PHP session.
    $browserATelegramId = $readSession($browserASessionId)['telegram_session_id'] ?? '';
    $linkCookie = $telegramCookieFrom($browserAHomeHeaders);
    $assert($linkCookie !== null && str_starts_with($linkCookie, 'tg_session=' . $browserATelegramId . ';'), 'A logged-in browser should receive the Telegram session cookie.');
    $assert(str_contains($linkCookie, 'Max-Age=2592000') && str_contains($linkCookie, 'HttpOnly') && str_contains($linkCookie, 'SameSite=Lax'), 'The Telegram session cookie should last 30 days and be HttpOnly.');

    // Simulate an expired PHP session (browser restart or server-side cleanup): only the link cookie remains.
    $linkOnly = 'tg_session=' . $browserATelegramId;
    [$restoredStatus, $restoredHeaders] = $request($port, '/', 'GET', [], $linkOnly);
    $assert($restoredStatus === 200, 'An authorized Telegram session should be restored without logging in again.');
    $restoredCookie = $cookieFrom($restoredHeaders);
    $assert($restoredCookie !== null, 'The restored browser should receive a new PHP session.');
    $sessionIds[] = $sessionIdFrom($restoredCookie);
    $restoredSession = $readSession($sessionIdFrom($restoredCookie));
    $assert(($restoredSession['logged_in'] ?? false) === true && ($restoredSession['telegram_session_id'] ?? null) === $browserATelegramId, 'The restored PHP session should use the same Telegram session.');
    $assert(($restoredSession['simulated_telegram_session_path'] ?? null) === $browserAPath, 'The restored browser should reuse the same Telegram session files.');
    [$restoredLoginStatus, $restoredLoginHeaders] = $request($port, '/login', 'GET', [], $linkOnly);
    $assert($restoredLoginStatus === 302 && ($restoredLoginHeaders['location'] ?? null) === '/', 'A restored browser visiting the login page should go straight home.');
    $restoredLoginCookie = $cookieFrom($restoredLoginHeaders);
    if ($restoredLoginCookie !== null) {
        $sessionIds[] = $sessionIdFrom($restoredLoginCookie);
    }

    // A restored session that cannot be checked right now is retried, never replaced by a new QR code.
    $unreachable = sys_get_temp_dir() . '/simulated-telegram-unreachable-' . md5($browserAPath);
    file_put_contents($unreachable, '1');
    try {
        [$downStatus, $downHeaders] = $request($port, '/', 'GET', [], $linkOnly);
        $assert($downStatus === 302 && ($downHeaders['location'] ?? null) === '/login', 'An unverifiable restored session should wait on the login page.');
        $downCookie = $cookieFrom($downHeaders);
        $assert($downCookie !== null, 'The unverifiable restored browser should receive a PHP session.');
        $sessionIds[] = $sessionIdFrom($downCookie);
        [$waitStatus, , $waitBody] = $request($port, '/login', 'GET', [], $downCookie . '; ' . $linkOnly);
        $assert($waitStatus === 200 && str_contains($waitBody, 'Reconnecting to your Telegram session'), 'The login page should say it is reconnecting.');
        $assert(str_contains($waitBody, 'http-equiv="refresh"') && str_contains($waitBody, 'href="/logout"'), 'The reconnecting page should retry automatically and offer to start over.');
        $assert(!str_contains($waitBody, 'qr-code'), 'No QR code should be shown while the restored session is being checked.');
        $downSession = $readSession($sessionIdFrom($downCookie));
        $assert(($downSession['telegram_session_restored'] ?? false) === true && !isset($downSession['simulated_qr_polls']), 'The restore should stay pending and no QR login should start.');
    } finally {
        @unlink($unreachable);
    }
    [$backStatus, $backHeaders] = $request($port, '/login', 'GET', [], $downCookie . '; ' . $linkOnly);
    $assert($backStatus === 302 && ($backHeaders['location'] ?? null) === '/', 'Once reachable again, the restored session should log in without a new QR code.');
    $assert(($readSession($sessionIdFrom($downCookie))['logged_in'] ?? false) === true, 'The retried restore should complete the login.');

    foreach (['tg_session=../web.session', 'tg_session=' . str_repeat('a', 64)] as $badLink) {
        [$badStatus, $badHeaders] = $request($port, '/', 'GET', [], $badLink);
        $assert($badStatus === 302 && ($badHeaders['location'] ?? null) === '/login', 'Invalid or unauthorized session cookies must not grant access.');
        $badCookie = $cookieFrom($badHeaders);
        if ($badCookie !== null) {
            $sessionIds[] = $sessionIdFrom($badCookie);
        }
    }

    // Browser B: independent session, account with two-step verification.
    [, $browserBHeaders] = $request($port, '/login');
    $browserBCookie = $cookieFrom($browserBHeaders);
    $assert($browserBCookie !== null, 'The second browser should receive its own PHP session cookie.');
    $browserBSessionId = $sessionIdFrom($browserBCookie);
    $sessionIds[] = $browserBSessionId;
    $browserBPath = $readSession($browserBSessionId)['simulated_telegram_session_path'] ?? null;
    $assert(is_string($browserBPath) && $browserBPath !== $browserAPath, 'Two browsers should use different Telegram session files.');
    $updateSession($browserBSessionId, ['simulated_qr_scan' => 'password']);
    [$passwordRedirectStatus] = $request($port, '/login', 'GET', [], $browserBCookie);
    $assert($passwordRedirectStatus === 302 && ($readSession($browserBSessionId)['step'] ?? null) === 'password', 'A QR login on a 2FA account should move to the password step.');
    [$passwordStatus, , $passwordBody] = $request($port, '/login', 'GET', [], $browserBCookie);
    $assert($passwordStatus === 200 && str_contains($passwordBody, 'Two-step verification password'), 'The 2FA step should show the password form.');
    $assert(!str_contains($passwordBody, 'http-equiv="refresh"'), 'The password step must not auto-refresh while the user types.');
    $assert(str_contains($passwordBody, 'href="/logout"'), 'The password step should offer a way to cancel.');
    [$wrongPasswordStatus, , $wrongPasswordBody] = $request($port, '/login', 'POST', ['password' => 'wrong'], $browserBCookie);
    $assert($wrongPasswordStatus === 200 && str_contains($wrongPasswordBody, 'Invalid simulated Telegram password.'), 'A rejected 2FA password should be reported.');
    $assert(str_contains($wrongPasswordBody, 'Two-step verification password'), 'A rejected 2FA password should keep the password step.');
    [$validPasswordStatus] = $request($port, '/login', 'POST', ['password' => 'valid-password'], $browserBCookie);
    $assert($validPasswordStatus === 302 && ($readSession($browserBSessionId)['logged_in'] ?? false) === true, 'The 2FA password should complete the QR login.');
    [$browserBHomeStatus] = $request($port, '/', 'GET', [], $browserBCookie);
    $assert($browserBHomeStatus === 200, 'The second browser should authenticate independently.');

    // Logout ends the Telegram session itself and forgets the link cookie.
    [$logoutStatus, $logoutHeaders] = $request($port, '/logout', 'GET', [], $browserACookie . '; ' . $linkOnly);
    $assert($logoutStatus === 302, 'Logout should redirect to the login page.');
    $assert(is_file(sys_get_temp_dir() . '/simulated-telegram-logout-' . md5($browserAPath)), 'Logout should log the Telegram session out, not only the PHP session.');
    $clearedCookie = $telegramCookieFrom($logoutHeaders);
    $assert($clearedCookie !== null && str_starts_with($clearedCookie, 'tg_session=;') && str_contains($clearedCookie, 'Max-Age=0'), 'Logout should delete the Telegram session cookie.');
    [$loggedOutStatus] = $request($port, '/', 'GET', [], $browserACookie);
    $assert($loggedOutStatus === 302, 'A logged-out browser should be redirected back to login.');
    [$afterLogoutStatus, $afterLogoutHeaders] = $request($port, '/', 'GET', [], $linkOnly);
    $assert($afterLogoutStatus === 302, 'A logged-out Telegram session must not be restored from an old cookie.');
    $afterLogoutCookie = $cookieFrom($afterLogoutHeaders);
    if ($afterLogoutCookie !== null) {
        $sessionIds[] = $sessionIdFrom($afterLogoutCookie);
    }
    [$otherBrowserStatus] = $request($port, '/', 'GET', [], $browserBCookie);
    $assert($otherBrowserStatus === 200, 'Logging out of one browser should not log out another.');
} finally {
    proc_terminate($server);
    foreach (glob(sys_get_temp_dir() . '/simulated-telegram-{auth,logout}-*', GLOB_BRACE) ?: [] as $marker) {
        unlink($marker);
    }
    proc_close($server);
    foreach (array_unique(array_filter($sessionIds, static fn(string $id): bool => $id !== '')) as $sessionId) {
        $sessionFile = rtrim($sessionDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sess_' . $sessionId;
        if (is_file($sessionFile)) {
            unlink($sessionFile);
        }
    }
}

fwrite(STDOUT, "Telegram login HTTP tests passed.\n");