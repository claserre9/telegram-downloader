<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Factory\AppFactory;
use Slim\Factory\ServerRequestCreatorFactory;
use Slim\ResponseEmitter;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use claserre9\BrowserDownload;
use claserre9\WebController;
use claserre9\WebTelegramClient;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI === 'cli-server') {
    $url = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = __DIR__ . $url;
    if ($url !== '/style.css' && is_file($file)) {
        return false;
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
\claserre9\TelegramSessionPath::restoreFromCookie(
    $_SESSION,
    $_COOKIE[\claserre9\TelegramSessionPath::COOKIE] ?? null
);

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$app = AppFactory::create();
$app->addRoutingMiddleware();
$app->addBodyParsingMiddleware();
$app->addErrorMiddleware(true, true, true);

$app->get('/style.css', static function (Request $request, Response $response): Response {
    $css = file_get_contents(__DIR__ . '/style.css');
    if ($css === false) {
        return $response->withStatus(404);
    }

    $response = $response
        ->withHeader('Content-Type', 'text/css; charset=UTF-8')
        ->withHeader('Content-Length', (string) strlen($css));

    if ($request->getMethod() !== 'HEAD') {
        $response->getBody()->write($css);
    }

    return $response;
});

$telegramClientConstructor = $GLOBALS['telegramWebClientConstructor'] ?? static function (int $apiId, string $apiHash, string $sessionPath): WebTelegramClient {
    return new WebTelegramClient($apiId, $apiHash, $sessionPath);
};
/** @var callable(int, string, string): WebTelegramClient $telegramClientConstructor */
$clientFactory = static function () use ($telegramClientConstructor): WebTelegramClient {
    $sessionsDir = __DIR__ . '/../sessions';
    static $client = null;

    if ($client === null) {
        if (!is_dir($sessionsDir)) {
            mkdir($sessionsDir, 0775, true);
        }
        if (!isset($_ENV['TELEGRAM_API_ID'], $_ENV['TELEGRAM_API_HASH'])) {
            throw new RuntimeException('TELEGRAM_API_ID and TELEGRAM_API_HASH must be set');
        }
        $client = $telegramClientConstructor(
            (int) $_ENV['TELEGRAM_API_ID'],
            (string) $_ENV['TELEGRAM_API_HASH'],
            \claserre9\TelegramSessionPath::forSession($_SESSION, $sessionsDir)
        );
    }

    return $client;
};

$twig = new Environment(new FilesystemLoader(__DIR__ . '/../templates'), [
    'autoescape' => 'html',
]);
$controller = new WebController($twig, $clientFactory(...));

$app->get('/', [$controller, 'home']);
$app->map(['GET', 'POST'], '/login', [$controller, 'login']);
$app->get('/logout', [$controller, 'logout']);
$app->get('/media', [$controller, 'media']);
$app->get('/thumbnail', [$controller, 'thumbnail']);
$app->get('/download', [$controller, 'download']);

$request = ServerRequestCreatorFactory::create()->createServerRequestFromGlobals();
$response = $app->handle($request);

// Keep the browser linked to its Telegram session for 30 days, independently of the
// short-lived PHP session; forget the link once the browser no longer has one (logout).
$telegramSessionId = \claserre9\TelegramSessionPath::idFrom($_SESSION);
if ($telegramSessionId !== null || isset($_COOKIE[\claserre9\TelegramSessionPath::COOKIE])) {
    $response = $response->withAddedHeader(
        'Set-Cookie',
        \claserre9\TelegramSessionPath::cookieHeader($telegramSessionId, $request->getUri()->getScheme() === 'https')
    );
}
if (!$response->hasHeader(BrowserDownload::STREAMED_RESPONSE_HEADER)) {
    (new ResponseEmitter())->emit($response);
}
