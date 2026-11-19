<?php

declare(strict_types=1);

namespace claserre9;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

final class WebController
{
    private const DIALOG_CACHE_VERSION = 3;

    /**
     * @param Closure(): WebTelegramClient $clientFactory
     */
    public function __construct(
        private readonly Environment $twig,
        private readonly Closure $clientFactory
    ) {
    }

    public function home(Request $request, Response $response): Response
    {
        if (!$this->isLoggedIn()) {
            return $this->redirect($response, '/login');
        }

        $params = $request->getQueryParams();
        $query = is_string($params['q'] ?? null) ? trim($params['q']) : '';
        $page = filter_var($params['page'] ?? 1, FILTER_VALIDATE_INT);
        $page = $page === false ? 1 : $page;
        $refresh = ($params['refresh'] ?? '') === '1';
        $error = '';

        if (
            $refresh
            || ($_SESSION['dialogs_version'] ?? null) !== self::DIALOG_CACHE_VERSION
            || !isset($_SESSION['dialogs'])
            || !is_array($_SESSION['dialogs'])
        ) {
            try {
                $_SESSION['dialogs'] = ($this->clientFactory)()->getConversations();
                $_SESSION['dialogs_version'] = self::DIALOG_CACHE_VERSION;
            } catch (\Throwable $e) {
                $error = 'Error loading conversations: ' . $e->getMessage();
            }
        }

        $dialogs = is_array($_SESSION['dialogs'] ?? null) ? $_SESSION['dialogs'] : [];
        $directory = DialogDirectory::filterAndPaginate($dialogs, $query, $page);
        foreach ($directory['items'] as &$dialog) {
            $dialog['mediaUrl'] = '/media?chat=' . rawurlencode((string) $dialog['id']);
        }
        unset($dialog);

        $querySuffix = $query !== '' ? '&q=' . rawurlencode($query) : '';
        $directory['previousUrl'] = $directory['page'] > 1
            ? '/?page=' . ($directory['page'] - 1) . $querySuffix
            : null;
        $directory['nextUrl'] = $directory['page'] < $directory['pages']
            ? '/?page=' . ($directory['page'] + 1) . $querySuffix
            : null;

        return $this->render($response, 'home.html.twig', [
            'title' => 'Telegram Media Downloader',
            'query' => $query,
            'error' => $error,
            'directory' => $directory,
            'refreshUrl' => '/?refresh=1' . ($query !== '' ? '&q=' . rawurlencode($query) : ''),
        ]);
    }

    public function login(Request $request, Response $response): Response
    {
        if ($request->getMethod() === 'GET' && $this->isLoggedIn()) {
            return $this->redirect($response, '/');
        }

        // QR code is the only login method; the password step follows a scan on accounts with 2FA.
        $step = ($_SESSION['step'] ?? null) === 'password' ? 'password' : 'qr';
        $message = '';

        if ($step === 'password' && $request->getMethod() === 'POST') {
            $data = (array) $request->getParsedBody();
            try {
                if (!is_string($data['password'] ?? null)) {
                    throw new \InvalidArgumentException('Password must be text.');
                }
                ($this->clientFactory)()->completePassword($data['password']);
                $this->completeLogin();
                return $this->redirect($response, '/');
            } catch (\Throwable $e) {
                $message = 'Login error: ' . $e->getMessage();
            }
        }

        $qrImage = '';
        $qrExpiresIn = 0;
        if ($step === 'qr') {
            try {
                $qr = ($this->clientFactory)()->qrLogin();
                if ($qr['status'] === 'logged_in') {
                    $this->completeLogin();
                    return $this->redirect($response, '/');
                }
                if ($qr['status'] === 'password') {
                    $_SESSION['step'] = 'password';
                    return $this->redirect($response, '/login');
                }
                $qrImage = 'data:image/svg+xml;base64,' . base64_encode($qr['svg']);
                $qrExpiresIn = $qr['expires_in'];
            } catch (\Throwable $e) {
                $message = 'QR login error: ' . $e->getMessage();
            }
        }

        return $this->render($response, 'login.html.twig', [
            'title' => 'Login to Telegram',
            'step' => $step,
            'message' => $message,
            'qrImage' => $qrImage,
            'qrExpiresIn' => $qrExpiresIn,
        ]);
    }

    public function logout(Request $request, Response $response): Response
    {
        $hasTelegramSession = TelegramSessionPath::idFrom($_SESSION) !== null
            || ($_SESSION['logged_in'] ?? false) === true;
        if ($hasTelegramSession) {
            try {
                ($this->clientFactory)()->logout();
            } catch (\Throwable $e) {
                // The local session files are already gone; the Telegram-side authorization may
                // survive and can be ended from Settings → Devices in the official app.
                error_log('Telegram logout failed: ' . $e->getMessage());
            }
        }

        $_SESSION = [];
        session_destroy();

        return $this->redirect($response, '/login');
    }

    public function media(Request $request, Response $response): Response
    {
        if (!$this->isLoggedIn()) {
            return $this->redirect($response, '/login');
        }

        $params = $request->getQueryParams();
        $chat = is_string($params['chat'] ?? null) ? $params['chat'] : '';
        $type = is_string($params['type'] ?? null) ? $params['type'] : '';
        $offsetId = isset($params['offset']) ? (int) $params['offset'] : 0;
        $messages = [];
        $nextOffset = 0;
        $error = '';

        if ($chat !== '') {
            try {
                $client = ($this->clientFactory)();
                $messages = $client->getMedia($chat, $type, $offsetId, 20);
                $messages = $client->filterMedia($messages, $type);
                usort($messages, static fn(array $a, array $b) => ($b['id'] ?? 0) <=> ($a['id'] ?? 0));
                if ($messages !== []) {
                    $ids = array_map(static fn(array $message) => $message['id'] ?? 0, $messages);
                    $minId = (int) min($ids);
                    $nextOffset = $minId > 1 ? $minId - 1 : 0;
                }
            } catch (\Throwable $e) {
                $error = 'Error fetching media: ' . $e->getMessage();
            }
        }

        $rows = [];
        if ($messages !== []) {
            $client = ($this->clientFactory)();
            foreach ($messages as $message) {
                $media = is_array($message['media'] ?? null) ? $message['media'] : [];
                $rawType = is_string($media['_'] ?? null) ? $media['_'] : '';
                $typeDisplay = match ($rawType) {
                    'messageMediaPhoto' => 'photo',
                    'messageMediaDocument' => 'document',
                    default => str_replace('messageMedia', '', $rawType),
                };

                if ($rawType === 'messageMediaDocument') {
                    $mime = $media['document']['mime_type'] ?? '';
                    if (is_string($mime) && str_starts_with($mime, 'video/')) {
                        $typeDisplay = 'video';
                    } elseif (is_string($mime) && str_starts_with($mime, 'audio/')) {
                        $typeDisplay = 'audio';
                    }
                }

                $messageId = (int) ($message['id'] ?? 0);
                $date = date('Y-m-d H:i:s', (int) ($message['date'] ?? 0));
                $fileName = 'N/A';
                $fileSize = 0;
                if (isset($media['document']) && is_array($media['document'])) {
                    $document = $media['document'];
                    $fileSize = (int) ($document['size'] ?? 0);
                    foreach ($document['attributes'] ?? [] as $attribute) {
                        if (($attribute['_'] ?? '') === 'documentAttributeFilename') {
                            $fileName = (string) ($attribute['file_name'] ?? $fileName);
                            break;
                        }
                    }
                } elseif ($rawType === 'messageMediaPhoto') {
                    $fileName = 'photo_' . $messageId . '.jpg';
                    if (isset($media['photo']['sizes']) && is_array($media['photo']['sizes'])) {
                        $sizes = $media['photo']['sizes'];
                        $lastSize = end($sizes);
                        if (is_array($lastSize)) {
                            $fileSize = (int) ($lastSize['size'] ?? 0);
                        }
                    }
                }

                $rows[] = [
                    'id' => $messageId,
                    'date' => $date,
                    'type' => $typeDisplay,
                    'name' => $fileName,
                    'size' => $client->formatSize($fileSize),
                    'downloadUrl' => '/download?' . http_build_query(
                        ['chat' => $chat, 'id' => $messageId],
                        '',
                        '&',
                        PHP_QUERY_RFC3986
                    ),
                ];
            }
        }

        $olderUrl = $nextOffset > 0
            ? '/media?' . http_build_query(
                ['chat' => $chat, 'type' => $type, 'offset' => $nextOffset],
                '',
                '&',
                PHP_QUERY_RFC3986
            )
            : null;

        return $this->render($response, 'media.html.twig', [
            'title' => 'Media',
            'chat' => $chat,
            'error' => $error,
            'rows' => $rows,
            'olderUrl' => $olderUrl,
        ]);
    }

    public function download(Request $request, Response $response): Response
    {
        if (!$this->isLoggedIn()) {
            return $this->redirect($response, '/login');
        }

        $params = $request->getQueryParams();
        $chat = $params['chat'] ?? null;
        $id = isset($params['id']) ? (int) $params['id'] : 0;

        if (!is_string($chat) || $chat === '' || $id === 0) {
            $response->getBody()->write('Invalid request');
            return $response->withStatus(400);
        }

        return BrowserDownload::stream($response, function () use ($chat, $id): void {
            ($this->clientFactory)()->downloadToBrowser($chat, $id);
        });
    }

    private function isLoggedIn(): bool
    {
        if (($_SESSION['logged_in'] ?? false) === true) {
            return true;
        }
        if (($_SESSION['telegram_session_restored'] ?? false) !== true) {
            return false;
        }

        // The PHP session expired but the browser cookie still names a Telegram session:
        // trust it only if that session is still authorized.
        unset($_SESSION['telegram_session_restored']);
        try {
            $authorized = ($this->clientFactory)()->isAuthorized();
        } catch (\Throwable) {
            $authorized = false;
        }
        if ($authorized) {
            $this->completeLogin();
        }

        return $authorized;
    }

    private function redirect(Response $response, string $path): Response
    {
        return $response->withHeader('Location', $path)->withStatus(302);
    }

    private function completeLogin(): void
    {
        $_SESSION['logged_in'] = true;
        // Also drops phone-code login data left by older versions of this app.
        unset(
            $_SESSION['step'],
            $_SESSION['phone'],
            $_SESSION['login_delivery_message'],
            $_SESSION['login_phone_code_hash'],
            $_SESSION['login_next_type'],
            $_SESSION['login_next_type_label'],
            $_SESSION['login_resend_at'],
            $_SESSION['login_password_step_manual'],
        );
    }

    /** @param array<string, mixed> $context */
    private function render(Response $response, string $template, array $context): Response
    {
        $response->getBody()->write($this->twig->render($template, $context));

        return $response;
    }
}
