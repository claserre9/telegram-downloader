<?php

declare(strict_types=1);

use claserre9\BrowserDownload;
use danog\MadelineProto\MTProtoTools\ResponseInfo;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\ResponseEmitter;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI === 'cli-server') {
    $mode = $_GET['mode'] ?? 'stream';
    $response = BrowserDownload::stream((new ResponseFactory())->createResponse(), static function () use ($mode): void {
        if ($mode === 'before-error') {
            throw new RuntimeException('Simulated pre-stream failure');
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="test.bin"');
        header('Content-Length: 6');
        echo 'abc';
        flush();

        if ($mode === 'interrupt') {
            usleep(200000);
            throw new RuntimeException('Simulated interrupted stream');
        }

        usleep(1200000);
        echo 'def';
        flush();
    });

    if (!$response->hasHeader(BrowserDownload::STREAMED_RESPONSE_HEADER)) {
        (new ResponseEmitter())->emit($response);
    }

    return;
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$media = [
    'size' => 6,
    'mime' => 'application/octet-stream',
    'name' => 'video',
    'ext' => '.mp4',
];

$fullResponse = ResponseInfo::parseHeaders('GET', [], $media);
$assert($fullResponse->getCode() === 200, 'A full download should return HTTP 200.');
$assert($fullResponse->getHeaders()['Content-Length'] === '6', 'A full download should preserve its size.');
$assert($fullResponse->getHeaders()['Content-Type'] === 'application/octet-stream', 'Downloads should use the attachment MIME type.');
$assert(str_starts_with((string) $fullResponse->getHeaders()['Content-Disposition'], 'attachment;'), 'Media should be forced to download.');
$assert(str_contains((string) $fullResponse->getHeaders()['Content-Disposition'], 'filename*=UTF-8\'\'video.mp4'), 'The generated response should retain the filename.');

$headResponse = ResponseInfo::parseHeaders('HEAD', [], $media);
$assert(!$headResponse->shouldServe(), 'HEAD requests should not send a body.');
$assert($headResponse->getHeaders()['Content-Length'] === '6', 'HEAD should retain the media size.');

$rangeResponse = ResponseInfo::parseHeaders('GET', ['range' => 'bytes=1-3'], $media);
$assert($rangeResponse->getCode() === 206, 'A valid range should return HTTP 206.');
$assert($rangeResponse->getHeaders()['Content-Range'] === 'bytes 1-3/6', 'A valid range should report its byte range.');
$assert($rangeResponse->getHeaders()['Content-Length'] === '3', 'A valid range should report its partial length.');
$assert($rangeResponse->getServeRange() === [1, 4], 'A valid range should serve only its requested bytes.');

$invalidRangeResponse = ResponseInfo::parseHeaders('GET', ['range' => 'bytes=8-9'], $media);
$assert($invalidRangeResponse->getCode() === 416, 'An invalid range should return HTTP 416.');

$factory = new ResponseFactory();
$streamed = false;
$streamResponse = BrowserDownload::stream($factory->createResponse(), static function () use (&$streamed): void {
    $streamed = true;
});
$assert($streamed, 'The download callback should be invoked.');
$assert($streamResponse->hasHeader(BrowserDownload::STREAMED_RESPONSE_HEADER), 'Native responses should be marked to skip Slim emission.');

$errorResponse = BrowserDownload::stream($factory->createResponse(), static function (): void {
    throw new RuntimeException('Simulated pre-stream failure');
});
$assert($errorResponse->getStatusCode() === 500, 'A failure before streaming should return HTTP 500.');
$assert(!$errorResponse->hasHeader(BrowserDownload::STREAMED_RESPONSE_HEADER), 'Pre-stream failures should remain normal Slim responses.');
$assert((string) $errorResponse->getBody() === 'Download error: Simulated pre-stream failure', 'Pre-stream errors should be returned to the browser.');

$socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
$assert(is_resource($socket), 'The HTTP test server should reserve an available local port.');
$address = stream_socket_get_name($socket, false);
fclose($socket);
$port = (int) substr((string) strrchr((string) $address, ':'), 1);
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', __DIR__, __FILE__],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
    $serverPipes
);
$assert(is_resource($server), 'The HTTP test server should start.');

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

    $curl = proc_open(
        ['curl', '--silent', '--show-error', '--no-buffer', 'http://127.0.0.1:' . $port . '/?mode=stream'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $curlPipes
    );
    $assert(is_resource($curl), 'The HTTP streaming request should start.');
    $startedAt = microtime(true);
    $firstChunk = fread($curlPipes[1], 3);
    $firstChunkTime = microtime(true) - $startedAt;
    $remainingChunks = stream_get_contents($curlPipes[1]);
    stream_get_contents($curlPipes[2]);
    fclose($curlPipes[1]);
    fclose($curlPipes[2]);
    $curlStatus = proc_close($curl);
    $assert($firstChunk === 'abc', 'The first HTTP chunk should arrive before the full download.');
    $assert($firstChunkTime < 1.0, 'The first HTTP chunk should not wait for the delayed final chunk.');
    $assert($remainingChunks === 'def', 'The remaining HTTP chunk should complete the file contents.');
    $assert($curlStatus === 0, 'The complete HTTP stream should finish successfully.');

    $errorCurl = proc_open(
        ['curl', '--silent', '--show-error', '--write-out', "\n%{http_code}", 'http://127.0.0.1:' . $port . '/?mode=before-error'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $errorCurlPipes
    );
    $errorOutput = stream_get_contents($errorCurlPipes[1]);
    stream_get_contents($errorCurlPipes[2]);
    fclose($errorCurlPipes[1]);
    fclose($errorCurlPipes[2]);
    $errorCurlStatus = proc_close($errorCurl);
    $assert($errorCurlStatus === 0, 'The pre-stream error request should finish successfully.');
    $assert($errorOutput === "Download error: Simulated pre-stream failure\n500", 'A pre-stream error should return HTTP 500.');

    $interruptCurl = proc_open(
        ['curl', '--silent', '--show-error', '--no-buffer', 'http://127.0.0.1:' . $port . '/?mode=interrupt'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $interruptPipes
    );
    $interruptedBody = stream_get_contents($interruptPipes[1]);
    stream_get_contents($interruptPipes[2]);
    fclose($interruptPipes[1]);
    fclose($interruptPipes[2]);
    proc_close($interruptCurl);
    $assert($interruptedBody === 'abc', 'An interrupted stream must not append an error message to file contents.');
} finally {
    proc_terminate($server);
    proc_close($server);
}

fwrite(STDOUT, "Browser download tests passed.\n");