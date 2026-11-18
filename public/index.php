<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\ServerRequestCreatorFactory;
use Slim\Factory\AppFactory;
use Slim\ResponseEmitter;
use claserre9\BrowserDownload;
use claserre9\DialogDirectory;
use claserre9\TelegramSessionPath;
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
        $sessionPath = TelegramSessionPath::forSession($_SESSION, $sessionsDir);
        $client = $telegramClientConstructor(
            (int) $_ENV['TELEGRAM_API_ID'],
            (string) $_ENV['TELEGRAM_API_HASH'],
            $sessionPath
        );
    }

    return $client;
};

$renderLayout = static function (string $title, string $content): string {
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$title}</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
<header><h1>{$title}</h1></header>
<main>{$content}</main>
</body>
</html>
HTML;
};

$redirect = static function (Response $response, string $path): Response {
    return $response->withHeader('Location', $path)->withStatus(302);
};

$isLoggedIn = static function (): bool {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
};

$dialogCacheVersion = 3;
/** @param callable(): WebTelegramClient $clientFactory */
$renderHome = static function (Response $response, callable $clientFactory, string $query, int $page, bool $refresh) use ($renderLayout, $dialogCacheVersion): Response {
    $error = '';
    if (
        $refresh
        || ($_SESSION['dialogs_version'] ?? null) !== $dialogCacheVersion
        || !isset($_SESSION['dialogs'])
        || !is_array($_SESSION['dialogs'])
    ) {
        try {
            $_SESSION['dialogs'] = $clientFactory()->getConversations();
            $_SESSION['dialogs_version'] = $dialogCacheVersion;
        } catch (Throwable $e) {
            $error = 'Error loading conversations: ' . $e->getMessage();
        }
    }

    $dialogs = is_array($_SESSION['dialogs'] ?? null) ? $_SESSION['dialogs'] : [];
    $directory = DialogDirectory::filterAndPaginate($dialogs, $query, $page);
    $escapedQuery = htmlspecialchars($query, ENT_QUOTES, 'UTF-8');
    $content = '<div class="home-layout">';
    $content .= '<section class="card conversation-card" aria-labelledby="conversations-heading">';
    $content .= '<div class="section-heading"><div><p class="eyebrow">YOUR LIBRARY</p><h2 id="conversations-heading">Conversations</h2></div>';
    $content .= '<a class="text-link" href="/logout">Logout</a></div>';
    $content .= '<p class="muted">Browse media from your chats, channels and groups.</p>';
    if ($error !== '') {
        $content .= '<p class="error" role="alert">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    $content .= '<form method="get" action="/" class="directory-search">';
    $content .= '<label>Search conversations<input type="search" name="q" value="' . $escapedQuery . '" placeholder="Name, username or ID"></label>';
    $content .= '<button type="submit">Search</button></form>';
    $content .= '<div class="directory-actions">';
    if ($query !== '') {
        $content .= '<a href="/">Clear search</a>';
    } else {
        $content .= '<span class="muted">Search by name, username or ID</span>';
    }
    $refreshUrl = '/?refresh=1' . ($query !== '' ? '&q=' . rawurlencode($query) : '');
    $content .= '<a class="text-link" href="' . htmlspecialchars($refreshUrl, ENT_QUOTES, 'UTF-8') . '">Refresh list</a></div>';

    if ($directory['total'] === 0 && $error === '') {
        if ($query !== '') {
            $content .= '<div class="empty-state"><h3>No matching conversations</h3><p class="muted">Try a different name, username or ID.</p><a href="/">Clear search</a></div>';
        } else {
            $content .= '<div class="empty-state"><h3>No conversations found</h3><p class="muted">Refresh the list or open a conversation manually.</p></div>';
        }
    } elseif ($directory['total'] > 0) {
        $content .= '<p class="directory-summary"><span><strong>' . $directory['total'] . '</strong> ' . ($directory['total'] === 1 ? 'conversation' : 'conversations') . '</span><span class="muted">Page ' . $directory['page'] . ' of ' . $directory['pages'] . '</span></p>';
        $content .= '<table class="directory-table"><thead><tr><th scope="col">Conversation</th><th scope="col">Type</th><th scope="col">ID</th><th scope="col">Action</th></tr></thead><tbody>';
        foreach ($directory['items'] as $dialog) {
            $mediaUrl = '/media?chat=' . rawurlencode((string) $dialog['id']);
            $content .= '<tr>';
            $content .= '<td data-label="Conversation"><span class="conversation-name">' . htmlspecialchars($dialog['name'], ENT_QUOTES, 'UTF-8') . '</span>';
            if (($dialog['username'] ?? '') !== '') {
                $content .= '<span class="conversation-username">@' . htmlspecialchars($dialog['username'], ENT_QUOTES, 'UTF-8') . '</span>';
            }
            $content .= '</td>';
            $content .= '<td data-label="Type">' . htmlspecialchars($dialog['type'], ENT_QUOTES, 'UTF-8') . '</td>';
            $content .= '<td data-label="ID">' . htmlspecialchars((string) $dialog['id'], ENT_QUOTES, 'UTF-8') . '</td>';
            $content .= '<td data-label="Action"><a class="browse-link" href="' . htmlspecialchars($mediaUrl, ENT_QUOTES, 'UTF-8') . '">Browse media <span aria-hidden="true">→</span></a></td>';
            $content .= '</tr>';
        }
        $content .= '</tbody></table>';
        $content .= '<nav class="pagination" aria-label="Conversation pages">';
        if ($directory['page'] > 1) {
            $previousUrl = '/?page=' . ($directory['page'] - 1) . ($query !== '' ? '&q=' . rawurlencode($query) : '');
            $content .= '<a href="' . htmlspecialchars($previousUrl, ENT_QUOTES, 'UTF-8') . '">Previous</a>';
        }
        if ($directory['page'] < $directory['pages']) {
            $nextUrl = '/?page=' . ($directory['page'] + 1) . ($query !== '' ? '&q=' . rawurlencode($query) : '');
            $content .= '<a href="' . htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8') . '">Next</a>';
        }
        $content .= '</nav>';
    } elseif ($error !== '') {
        $content .= '<div class="empty-state"><h3>Conversation list unavailable</h3><p class="muted">You can try refreshing or use the manual form.</p></div>';
    }
    $content .= '</section>';

    $content .= <<<HTML
<aside class="card manual-card" aria-labelledby="manual-heading">
    <p class="eyebrow">DIRECT ACCESS</p>
    <h2 id="manual-heading">Open a conversation</h2>
    <p class="muted">You can also enter a public username, chat ID or phone number.</p>
    <form method="get" action="/media" class="stacked-form">
        <label>Chat, channel or group
            <input type="text" name="chat" required placeholder="@channel or chat id">
        </label>
        <label>Type
            <select name="type">
                <option value="">All</option>
                <option value="photo">Photos</option>
                <option value="video">Videos</option>
                <option value="document">Documents</option>
                <option value="audio">Audio</option>
            </select>
        </label>
        <button type="submit">Browse</button>
    </form>
</aside>
</div>
HTML;

    $response->getBody()->write($renderLayout('Telegram Media Downloader', $content));
    return $response;
};

$app->get('/', function (Request $request, Response $response) use ($isLoggedIn, $redirect, $renderHome, $clientFactory) {
    if (!$isLoggedIn()) {
        return $redirect($response, '/login');
    }

    $params = $request->getQueryParams();
    $query = is_string($params['q'] ?? null) ? trim($params['q']) : '';
    $page = filter_var($params['page'] ?? 1, FILTER_VALIDATE_INT);
    $page = $page === false ? 1 : $page;
    $refresh = ($params['refresh'] ?? '') === '1';

    return $renderHome($response, $clientFactory, $query, $page, $refresh);
});

$app->map(['GET', 'POST'], '/login', function (Request $request, Response $response) use ($clientFactory, $renderLayout, $redirect) {
    $step = $_SESSION['step'] ?? 'phone';
    $message = '';

    if ($request->getMethod() === 'POST') {
        $data = (array) $request->getParsedBody();

        if ($step === 'phone' && isset($data['phone'])) {
            try {
                $clientFactory()->requestCode($data['phone']);
                $_SESSION['phone'] = $data['phone'];
                $_SESSION['step'] = 'code';
                return $redirect($response, '/login');
            } catch (Throwable $e) {
                $message = 'Error requesting code: ' . $e->getMessage();
            }
        } elseif ($step === 'code' && isset($data['code'])) {
            try {
                $clientFactory()->completeCode($data['code'], $data['password'] ?? null);
                $_SESSION['logged_in'] = true;
                $_SESSION['step'] = 'phone';
                return $redirect($response, '/');
            } catch (Throwable $e) {
                $message = 'Login error: ' . $e->getMessage();
            }
        }
    }

    $body = '<section class="card">';
    if ($message !== '') {
        $body .= '<p class="error">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    if ($step === 'phone') {
        $body .= <<<HTML
    <form method="post" class="stacked-form">
        <label>Phone number
            <input type="text" name="phone" required placeholder="+123456789">
        </label>
        <button type="submit">Send code</button>
    </form>
HTML;
    } else {
        $body .= <<<HTML
    <form method="post" class="stacked-form">
        <label>Code
            <input type="text" name="code" required>
        </label>
        <label>Password (if 2FA enabled)
            <input type="password" name="password">
        </label>
        <button type="submit">Login</button>
    </form>
HTML;
    }

    $body .= '</section>';

    $response->getBody()->write($renderLayout('Login to Telegram', $body));
    return $response;
});

$app->get('/logout', function (Request $request, Response $response) use ($redirect) {
    $_SESSION = [];
    session_destroy();

    return $redirect($response, '/login');
});

$app->get('/media', function (Request $request, Response $response) use ($clientFactory, $renderLayout, $redirect, $isLoggedIn) {
    if (!$isLoggedIn()) {
        return $redirect($response, '/login');
    }

    $params = $request->getQueryParams();
    $chat = $params['chat'] ?? '';
    $type = $params['type'] ?? '';
    $offsetId = isset($params['offset']) ? (int) $params['offset'] : 0;

    $messages = [];
    $nextOffset = 0;
    $error = '';

    if ($chat !== '') {
        try {
            $client = $clientFactory();
            $messages = $client->getMedia($chat, $type, $offsetId, 20);
            $messages = $client->filterMedia($messages, $type);
            usort($messages, static fn(array $a, array $b) => ($b['id'] ?? 0) <=> ($a['id'] ?? 0));
            if (!empty($messages)) {
                $ids = array_map(static fn(array $m) => $m['id'] ?? 0, $messages);
                $minId = (int) min($ids);
                $nextOffset = $minId > 1 ? $minId - 1 : 0;
            }
        } catch (Throwable $e) {
            $error = 'Error fetching media: ' . $e->getMessage();
        }
    }

    $body = '<section class="card">';
    $body .= '<p class="muted"><a href="/">Back</a></p>';

    if ($error !== '') {
        $body .= '<p class="error">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    if ($chat === '') {
        $body .= '<p class="muted">No chat selected.</p>';
    } else {
        $body .= '<h2>Media in ' . htmlspecialchars($chat, ENT_QUOTES, 'UTF-8') . '</h2>';
        if (empty($messages)) {
            $body .= '<p class="muted">No media found.</p>';
        } else {
            $body .= '<table class="media-table"><thead><tr><th scope="col">ID</th><th scope="col">Date</th><th scope="col">Type</th><th scope="col">Name</th><th scope="col">Size</th><th scope="col">Action</th></tr></thead><tbody>';
            $client = $clientFactory();
            foreach ($messages as $msg) {
                $rawType = $msg['media']['_'] ?? '';
                $typeDisplay = match($rawType) {
                    'messageMediaPhoto' => 'photo',
                    'messageMediaDocument' => 'document',
                    default => str_replace('messageMedia', '', $rawType)
                };

                // Better type detection for videos/audio (which are documents in Telegram)
                if ($rawType === 'messageMediaDocument') {
                    $mime = $msg['media']['document']['mime_type'] ?? '';
                    if (str_starts_with($mime, 'video/')) {
                        $typeDisplay = 'video';
                    } elseif (str_starts_with($mime, 'audio/')) {
                        $typeDisplay = 'audio';
                    }
                }

                $date = date('Y-m-d H:i:s', $msg['date'] ?? 0);
                
                $fileName = 'N/A';
                $fileSize = 0;
                if (isset($msg['media']['document'])) {
                    $doc = $msg['media']['document'];
                    $fileSize = (int)($doc['size'] ?? 0);
                    if (isset($doc['attributes'])) {
                        foreach ($doc['attributes'] as $attr) {
                            if ($attr['_'] === 'documentAttributeFilename') {
                                $fileName = $attr['file_name'];
                                break;
                            }
                        }
                    }
                } elseif ($rawType === 'messageMediaPhoto') {
                    $fileName = 'photo_' . $msg['id'] . '.jpg';
                    if (isset($msg['media']['photo']['sizes'])) {
                        $lastSize = end($msg['media']['photo']['sizes']);
                        $fileSize = (int)($lastSize['size'] ?? 0);
                    }
                }

                $formattedSize = $client->formatSize($fileSize);

                $body .= '<tr>';
                $body .= '<td data-label="ID">' . (int) $msg['id'] . '</td>';
                $body .= '<td data-label="Date">' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '</td>';
                $body .= '<td data-label="Type">' . htmlspecialchars($typeDisplay, ENT_QUOTES, 'UTF-8') . '</td>';
                $body .= '<td data-label="Name">' . htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8') . '</td>';
                $body .= '<td data-label="Size">' . htmlspecialchars($formattedSize, ENT_QUOTES, 'UTF-8') . '</td>';
                $body .= '<td data-label="Action"><a href="/download?chat=' . urlencode($chat) . '&id=' . (int) $msg['id'] . '">Download</a></td>';
                $body .= '</tr>';
            }
            $body .= '</tbody></table>';
            if ($nextOffset) {
                $body .= '<p><a href="/media?chat=' . urlencode($chat) . '&type=' . urlencode($type) . '&offset=' . $nextOffset . '">Older messages</a></p>';
            }
        }
    }

    $body .= '</section>';

    $response->getBody()->write($renderLayout('Media', $body));
    return $response;
});

$app->get('/download', function (Request $request, Response $response) use ($clientFactory, $redirect, $isLoggedIn) {
    if (!$isLoggedIn()) {
        return $redirect($response, '/login');
    }

    $params = $request->getQueryParams();
    $chat = $params['chat'] ?? null;
    $id = isset($params['id']) ? (int) $params['id'] : 0;

    if (!$chat || !$id) {
        $response->getBody()->write('Invalid request');
        return $response->withStatus(400);
    }

    return BrowserDownload::stream($response, static function () use ($clientFactory, $chat, $id): void {
        $clientFactory()->downloadToBrowser($chat, $id);
    });
});

$request = ServerRequestCreatorFactory::create()->createServerRequestFromGlobals();
$response = $app->handle($request);
if (!$response->hasHeader(BrowserDownload::STREAMED_RESPONSE_HEADER)) {
    (new ResponseEmitter())->emit($response);
}
