<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use claserre9\WebTelegramClient;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI === 'cli-server') {
    $url = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = __DIR__ . $url;
    if (is_file($file)) {
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

$clientFactory = static function (): WebTelegramClient {
    $sessionsDir = __DIR__ . '/../sessions';
    $sessionPath = $sessionsDir . '/web.session';
    static $client = null;

    if ($client === null) {
        if (!is_dir($sessionsDir)) {
            mkdir($sessionsDir, 0775, true);
        }
        if (!isset($_ENV['TELEGRAM_API_ID'], $_ENV['TELEGRAM_API_HASH'])) {
            throw new RuntimeException('TELEGRAM_API_ID and TELEGRAM_API_HASH must be set');
        }
        $client = new WebTelegramClient(
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

$renderHome = static function (Response $response) use ($renderLayout): Response {
    $content = <<<HTML
<section class="card">
    <p class="muted">Browse media from any chat, channel or group.</p>
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
    <p class="muted"><a href="/logout">Logout</a></p>
</section>
HTML;

    $response->getBody()->write($renderLayout('Telegram Media Downloader', $content));
    return $response;
};

$app->get('/', function (Request $request, Response $response) use ($isLoggedIn, $redirect, $renderHome) {
    if (!$isLoggedIn()) {
        return $redirect($response, '/login');
    }

    return $renderHome($response);
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
            $body .= '<table class="media-table"><tr><th>ID</th><th>Date</th><th>Type</th><th>Name</th><th>Size</th><th>Action</th></tr>';
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
                $body .= '<td>' . (int) $msg['id'] . '</td>';
                $body .= '<td>' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '</td>';
                $body .= '<td>' . htmlspecialchars($typeDisplay, ENT_QUOTES, 'UTF-8') . '</td>';
                $body .= '<td>' . htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8') . '</td>';
                $body .= '<td>' . htmlspecialchars($formattedSize, ENT_QUOTES, 'UTF-8') . '</td>';
                $body .= '<td><a href="/download?chat=' . urlencode($chat) . '&id=' . (int) $msg['id'] . '">Download</a></td>';
                $body .= '</tr>';
            }
            $body .= '</table>';
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

    try {
        $downloadDir = __DIR__ . '/../downloads';
        if (!is_dir($downloadDir)) {
            mkdir($downloadDir, 0775, true);
        }

        $file = $clientFactory()->downloadMedia($chat, $id, $downloadDir);
        if (!is_file($file)) {
            throw new RuntimeException('Download failed');
        }

        $size = filesize($file);
        if ($size === 0) {
            throw new RuntimeException('Downloaded file is empty');
        }

        $mimeType = mime_content_type($file) ?: 'application/octet-stream';
        $name = basename($file);
        
        // Clean any previous output to avoid corruption
        if (ob_get_level()) {
            ob_end_clean();
        }

        $stream = new \claserre9\UnlinkStream(fopen($file, 'rb'), $file);

        return $response
            ->withHeader('Content-Type', $mimeType)
            ->withHeader('Content-Length', (string)$size)
            ->withHeader('Content-Disposition', 'attachment; filename="' . str_replace('"', '\"', $name) . '"; filename*=UTF-8\'\'' . rawurlencode($name))
            ->withBody($stream);
    } catch (Throwable $e) {
        $response->getBody()->write('Download error: ' . $e->getMessage());
        return $response->withStatus(500);
    }
});

$app->run();
