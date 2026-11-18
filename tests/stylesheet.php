<?php

declare(strict_types=1);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$expectedCss = file_get_contents(__DIR__ . '/../public/style.css');
$assert($expectedCss !== false, 'The public stylesheet should exist.');

$request = static function (int $port, string $path, string $method = 'GET'): array {
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'ignore_errors' => true,
            'timeout' => 5,
        ],
    ]);
    $body = file_get_contents('http://127.0.0.1:' . $port . $path, false, $context);
    $responseHeaders = $http_response_header;
    $status = 0;
    $headers = [];

    if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $matches)) {
        $status = (int) $matches[1];
    }

    foreach (array_slice($responseHeaders, 1) as $header) {
        if (str_contains($header, ':')) {
            [$name, $value] = explode(':', $header, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }

    return [$status, $headers, $body === false ? '' : $body];
};

$projectRoot = dirname(__DIR__);
$publicRoot = $projectRoot . '/public';

foreach ([$projectRoot, $publicRoot] as $documentRoot) {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if (!is_resource($socket)) {
        throw new RuntimeException('An available local port should be reserved.');
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr((string) strrchr((string) $address, ':'), 1);

    $server = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $documentRoot, $publicRoot . '/index.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
        $serverPipes
    );
    if (!is_resource($server)) {
        throw new RuntimeException('The HTTP test server should start.');
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
        $assert($serverReady, 'The HTTP test server should accept requests.');

        [$loginStatus] = $request($port, '/login');
        $assert($loginStatus === 200, 'The login page should load with document root ' . $documentRoot . '.');

        [$cssStatus, $cssHeaders, $cssBody] = $request($port, '/style.css');
        $assert($cssStatus === 200, 'The stylesheet should load with document root ' . $documentRoot . '.');
        $assert(str_starts_with($cssHeaders['content-type'] ?? '', 'text/css'), 'The stylesheet should have a CSS content type.');
        $assert($cssBody === $expectedCss, 'The stylesheet response should contain the public CSS file.');

        [$headStatus, $headHeaders, $headBody] = $request($port, '/style.css', 'HEAD');
        $assert($headStatus === 200, 'HEAD should succeed for the stylesheet with document root ' . $documentRoot . '.');
        $assert(str_starts_with($headHeaders['content-type'] ?? '', 'text/css'), 'HEAD should retain the CSS content type.');
        $assert($headBody === '', 'HEAD should not return a response body.');

        [$missingStatus] = $request($port, '/missing-resource.css');
        $assert($missingStatus === 404, 'A missing resource should remain unavailable.');
    } finally {
        proc_terminate($server);
        proc_close($server);
    }
}

fwrite(STDOUT, "Stylesheet HTTP tests passed.\n");