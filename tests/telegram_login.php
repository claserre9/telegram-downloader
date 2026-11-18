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
            $parsedHeaders[strtolower(trim($name))] = trim($value);
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

$sessionSavePath = session_save_path();
$sessionPathParts = explode(';', is_string($sessionSavePath) ? $sessionSavePath : '');
$sessionDirectory = end($sessionPathParts);
if (!is_string($sessionDirectory) || $sessionDirectory === '') {
    throw new RuntimeException('The PHP session directory should be available for the HTTP test.');
}

$projectRoot = dirname(__DIR__);
$legacySessionId = 'legacy' . bin2hex(random_bytes(16));
$sessionIds = [$legacySessionId];
session_id($legacySessionId);
if (!session_start()) {
    throw new RuntimeException('The legacy test PHP session should start.');
}
$_SESSION = ['logged_in' => true];
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

    [$loginStatus, $loginHeaders] = $request($port, '/login');
    $assert($loginStatus === 200, 'A new browser should see the phone login form.');
    $browserACookie = $cookieFrom($loginHeaders);
    $assert($browserACookie !== null, 'The first browser should receive a PHP session cookie.');
    $browserASessionId = $sessionIdFrom($browserACookie);
    $sessionIds[] = $browserASessionId;

    [$requestCodeStatus, $requestCodeHeaders] = $request(
        $port,
        '/login',
        'POST',
        ['phone' => '+12025550123'],
        $browserACookie
    );
    $assert($requestCodeStatus === 302, 'A fresh browser should be able to request a Telegram code.');
    $browserACookie = $cookieFrom($requestCodeHeaders) ?? $browserACookie;
    $browserASession = $readSession($browserASessionId);
    $browserAPath = $browserASession['simulated_telegram_session_path'] ?? null;
    $assert(is_string($browserAPath) && str_ends_with($browserAPath, '/web-' . ($browserASession['telegram_session_id'] ?? '') . '.session'), 'The code request should use the browser-specific Telegram session.');

    [$browserBLoginStatus, $browserBHeaders] = $request($port, '/login');
    $assert($browserBLoginStatus === 200, 'A second browser should receive an independent login page.');
    $browserBCookie = $cookieFrom($browserBHeaders);
    $assert($browserBCookie !== null, 'The second browser should receive its own PHP session cookie.');
    $browserBSessionId = $sessionIdFrom($browserBCookie);
    $sessionIds[] = $browserBSessionId;
    [$browserBCodeStatus, $browserBCodeHeaders] = $request(
        $port,
        '/login',
        'POST',
        ['phone' => '+12025550124'],
        $browserBCookie
    );
    $assert($browserBCodeStatus === 302, 'The second browser should be able to request a code independently.');
    $browserBCookie = $cookieFrom($browserBCodeHeaders) ?? $browserBCookie;
    $browserBSession = $readSession($browserBSessionId);
    $browserBPath = $browserBSession['simulated_telegram_session_path'] ?? null;
    $assert(is_string($browserBPath) && $browserBPath !== $browserAPath, 'Two browsers should use different Telegram session files.');

    [$invalidCodeStatus, , $invalidCodeBody] = $request(
        $port,
        '/login',
        'POST',
        ['code' => 'wrong'],
        $browserACookie
    );
    $assert($invalidCodeStatus === 200 && str_contains($invalidCodeBody, 'Invalid simulated Telegram code.'), 'The login flow should report invalid codes without losing its session.');

    [$browserALoginStatus] = $request($port, '/login', 'POST', ['code' => '12345'], $browserACookie);
    $assert($browserALoginStatus === 302, 'The requested code should validate against the same browser session.');
    [$browserAHomeStatus] = $request($port, '/', 'GET', [], $browserACookie);
    $assert($browserAHomeStatus === 200, 'A successfully authenticated browser should reach the home page.');

    [$browserBPendingStatus, , $browserBPendingBody] = $request($port, '/login', 'GET', [], $browserBCookie);
    $assert($browserBPendingStatus === 200 && str_contains($browserBPendingBody, 'name="code"'), 'Logging in on one browser should not change the other browser login flow.');
    [$browserBLoginStatus] = $request($port, '/login', 'POST', ['code' => '12345'], $browserBCookie);
    $assert($browserBLoginStatus === 302, 'The second browser should validate its own requested code.');
    [$browserBHomeStatus] = $request($port, '/', 'GET', [], $browserBCookie);
    $assert($browserBHomeStatus === 200, 'The second browser should authenticate independently.');

    [$logoutStatus] = $request($port, '/logout', 'GET', [], $browserACookie);
    $assert($logoutStatus === 302, 'Logout should clear the current browser session.');
    [$loggedOutStatus] = $request($port, '/', 'GET', [], $browserACookie);
    $assert($loggedOutStatus === 302, 'A logged-out browser should be redirected back to login.');
    [$otherBrowserStatus] = $request($port, '/', 'GET', [], $browserBCookie);
    $assert($otherBrowserStatus === 200, 'Logging out of one browser should not log out another.');
} finally {
    proc_terminate($server);
    proc_close($server);
    foreach (array_unique(array_filter($sessionIds, static fn(string $id): bool => $id !== '')) as $sessionId) {
        $sessionFile = rtrim($sessionDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sess_' . $sessionId;
        if (is_file($sessionFile)) {
            unlink($sessionFile);
        }
    }
}

fwrite(STDOUT, "Telegram login HTTP tests passed.\n");