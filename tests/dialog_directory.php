<?php

declare(strict_types=1);

use claserre9\DialogDirectory;

require __DIR__ . '/../vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$pages = [
    '0:0:0' => [
        'count' => 3,
        'dialogs' => [
            ['peer' => ['_' => 'peerUser', 'user_id' => 11], 'top_message' => 101],
            ['peer' => ['_' => 'peerChannel', 'channel_id' => 22], 'top_message' => 202],
        ],
        'messages' => [
            ['_' => 'message', 'id' => 101, 'date' => 300, 'peer_id' => ['_' => 'peerUser', 'user_id' => 11]],
            ['_' => 'message', 'id' => 202, 'date' => 200, 'peer_id' => ['_' => 'peerChannel', 'channel_id' => 22]],
        ],
    ],
    '0:200:202:-10022' => [
        'count' => 3,
        'dialogs' => [
            ['peer' => ['_' => 'peerChat', 'chat_id' => 33], 'top_message' => 303],
        ],
        'messages' => [
            ['_' => 'message', 'id' => 303, 'date' => 100, 'peer_id' => ['_' => 'peerChat', 'chat_id' => 33]],
        ],
    ],
    '1:0:0' => [
        'count' => 2,
        'dialogs' => [
            ['peer' => ['_' => 'peerChannel', 'channel_id' => 22], 'top_message' => 202],
            ['peer' => ['_' => 'peerUser', 'user_id' => 44], 'top_message' => 404],
        ],
        'messages' => [
            ['_' => 'message', 'id' => 202, 'date' => 200, 'peer_id' => ['_' => 'peerChannel', 'channel_id' => 22]],
            ['_' => 'message', 'id' => 404, 'date' => 400, 'peer_id' => ['_' => 'peerUser', 'user_id' => 44]],
        ],
    ],
];

$fetchPage = static function (int $folderId, int $offsetDate, int $offsetId, int|array|string $offsetPeer, int $limit) use ($pages): array {
    if ($limit !== 2) {
        throw new RuntimeException('The requested dialog batch size should be forwarded.');
    }

    $key = $folderId . ':' . $offsetDate . ':' . $offsetId;
    if ($offsetDate !== 0) {
        if (!is_int($offsetPeer) && !is_string($offsetPeer)) {
            throw new RuntimeException('The next page should receive a usable peer offset.');
        }
        $key .= ':' . (string) $offsetPeer;
    }

    return $pages[$key] ?? ['count' => 0, 'dialogs' => [], 'messages' => []];
};
$getInfo = static function (array $peer): array {
    return match ($peer['_']) {
        'peerUser' => $peer['user_id'] === 11
            ? ['bot_api_id' => 11, 'type' => 'user', 'User' => ['first_name' => 'Alice', 'last_name' => 'Ng']]
            : ['bot_api_id' => 44, 'type' => 'user', 'User' => ['first_name' => 'Bob']],
        'peerChannel' => ['bot_api_id' => -10022, 'type' => 'channel', 'Chat' => ['title' => 'Film Haitien']],
        'peerChat' => ['bot_api_id' => -33, 'type' => 'chat', 'Chat' => ['title' => 'Friends']],
        default => [],
    };
};

$dialogs = DialogDirectory::fetchAll($fetchPage, $getInfo, 2);
$assert(count($dialogs) === 4, 'Dialogs from both folders should be included and duplicates removed.');
$assert($dialogs[0]['name'] === 'Alice Ng', 'Private chat names should use the first and last name.');
$assert($dialogs[1]['id'] === -10022 && $dialogs[1]['type'] === 'channel', 'Channel IDs should be usable for media links.');
$assert($dialogs[2]['name'] === 'Friends', 'Group titles should be retained.');
$assert($dialogs[3]['name'] === 'Bob', 'Archived private chats should be included.');

$filtered = DialogDirectory::filterAndPaginate($dialogs, 'film', 1, 1);
$assert($filtered['total'] === 1 && $filtered['items'][0]['id'] === -10022, 'Search should match conversation names.');
$paged = DialogDirectory::filterAndPaginate($dialogs, '', 2, 2);
$assert($paged['total'] === 4 && $paged['pages'] === 2 && count($paged['items']) === 2, 'The directory should paginate by the requested page size.');
$clamped = DialogDirectory::filterAndPaginate($dialogs, '', 999, 2);
$assert($clamped['page'] === 2, 'Out-of-range pages should be clamped to the last page.');
$fallback = DialogDirectory::fetchAll(
    static fn(): array => ['count' => 1, 'dialogs' => [['peer' => ['_' => 'peerChannel', 'channel_id' => 77], 'top_message' => 1]], 'messages' => []],
    static fn(): array => []
);
$assert($fallback[0]['id'] === '-10077' && $fallback[0]['name'] === 'Chat -10077', 'Missing peer metadata should retain a usable fallback entry.');

$numericPages = [
    '0:0:0' => [
        'count' => 3,
        'dialogs' => [
            ['peer' => 501, 'top_message' => 77],
            ['peer' => ['_' => 'peerUser', 'user_id' => 501], 'top_message' => 77],
            ['peer' => -10022, 'top_message' => 77],
            ['_' => 'dialogFolder', 'folder_id' => 1],
        ],
        'messages' => [
            ['_' => 'message', 'id' => 77, 'date' => 300, 'peer_id' => 999, 'from_id' => 501, 'resolved_peer_id' => 501],
            ['_' => 'message', 'id' => 77, 'date' => 200, 'peer_id' => -10022, 'resolved_peer_id' => -10022],
        ],
    ],
    '0:200:77:-10022' => [
        'count' => 3,
        'dialogs' => [
            ['peer' => -33, 'top_message' => 88],
        ],
        'messages' => [
            ['_' => 'message', 'id' => 88, 'date' => 100, 'peer_id' => -33, 'resolved_peer_id' => -33],
        ],
    ],
    '1:0:0' => [
        'count' => 1,
        'dialogs' => [
            ['peer' => 88, 'top_message' => 99],
        ],
        'messages' => [
            ['_' => 'message', 'id' => 99, 'date' => 50, 'peer_id' => 88, 'resolved_peer_id' => 88],
        ],
    ],
];
$numericDialogs = DialogDirectory::fetchAll(
    static function (int $folderId, int $offsetDate, int $offsetId, int|array|string $offsetPeer, int $limit) use ($numericPages): array {
        $key = $folderId . ':' . $offsetDate . ':' . $offsetId;
        if ($offsetDate !== 0) {
            $key .= ':' . (string) $offsetPeer;
        }

        return $numericPages[$key] ?? ['count' => 0, 'dialogs' => [], 'messages' => []];
    },
    static function (mixed $peer): array {
        $id = is_array($peer)
            ? match ($peer['_'] ?? '') {
                'peerUser' => (string) ($peer['user_id'] ?? ''),
                'peerChat' => (string) ($peer['chat_id'] ?? ''),
                'peerChannel' => '-100' . (string) ($peer['channel_id'] ?? ''),
                default => '',
            }
            : (string) $peer;

        return match ($id) {
            '501' => ['bot_api_id' => 501, 'type' => 'user', 'User' => ['first_name' => 'Incoming User']],
            '-10022' => ['bot_api_id' => -10022, 'type' => 'channel', 'Chat' => ['title' => 'Numeric Channel']],
            '-33' => ['bot_api_id' => -33, 'type' => 'chat', 'Chat' => ['title' => 'Numeric Group']],
            '88' => ['bot_api_id' => 88, 'type' => 'user', 'User' => ['first_name' => 'Archived User']],
            default => [],
        };
    },
    2,
    static fn(array $message): int => (int) $message['resolved_peer_id']
);
$assert(count($numericDialogs) === 4, 'Numeric user, channel, group and archived peers should be normalized and paginated.');
$assert($numericDialogs[0]['id'] === 501 && $numericDialogs[0]['name'] === 'Incoming User', 'A private incoming message should resolve to its sender, not the current account.');
$assert($numericDialogs[1]['id'] === -10022 && $numericDialogs[1]['type'] === 'channel', 'Numeric channel peers should retain their Bot API ID and type.');
$assert($numericDialogs[2]['id'] === -33 && $numericDialogs[2]['name'] === 'Numeric Group', 'Numeric group peers should remain available after pagination.');
$assert($numericDialogs[3]['id'] === 88 && $numericDialogs[3]['name'] === 'Archived User', 'Numeric peers from the archived folder should also be listed.');
$metadataFailureFallback = DialogDirectory::fetchAll(
    static fn(): array => ['count' => 1, 'dialogs' => [['peer' => ['_' => 'peerUser', 'user_id' => 71], 'top_message' => 1]], 'messages' => []],
    static function (): array {
        throw new RuntimeException('Simulated getInfo failure.');
    }
);
$assert($metadataFailureFallback[0]['id'] === 71 && $metadataFailureFallback[0]['type'] === 'user', 'A metadata lookup failure should still leave a usable entry when its peer ID is known.');

$invalidResponseRejected = false;
try {
    DialogDirectory::fetchAll(static fn(): array => [], static fn(): array => []);
} catch (RuntimeException) {
    $invalidResponseRejected = true;
}
$assert($invalidResponseRejected, 'A Telegram response without a dialogs list should be reported as invalid, not as an empty directory.');

$cursorFallback = DialogDirectory::fetchAll(
    static function (int $folderId, int $offsetDate, int $offsetId, int|array|string $offsetPeer, int $limit): array {
        if ($folderId === 1) {
            return ['count' => 0, 'dialogs' => [], 'messages' => []];
        }
        if ($offsetDate === 0) {
            return [
                'count' => 5,
                'dialogs' => [
                    ['peer' => ['_' => 'peerUser', 'user_id' => 9], 'top_message' => 909],
                    ['peer' => ['_' => 'peerChannel', 'channel_id' => 22], 'top_message' => 202],
                ],
                'messages' => [['_' => 'message', 'id' => 909, 'date' => 90, 'peer_id' => ['_' => 'peerUser', 'user_id' => 9]]],
            ];
        }

        if ($offsetDate === 90 && $offsetId === 909 && $offsetPeer === 9) {
            return [
                'count' => 5,
                'dialogs' => [
                    ['peer' => ['_' => 'peerChannel', 'channel_id' => 22], 'top_message' => 202],
                    ['peer' => ['_' => 'peerUser', 'user_id' => 11], 'top_message' => 101],
                ],
                'messages' => [
                    ['_' => 'message', 'id' => 202, 'date' => 80, 'peer_id' => ['_' => 'peerChannel', 'channel_id' => 22]],
                    ['_' => 'message', 'id' => 101, 'date' => 70, 'peer_id' => ['_' => 'peerUser', 'user_id' => 11]],
                ],
            ];
        }

        if ($offsetDate === 70 && $offsetId === 101 && $offsetPeer === 11) {
            return [
                'count' => 5,
                'dialogs' => [
                    ['peer' => ['_' => 'peerUser', 'user_id' => 11], 'top_message' => 101],
                    ['peer' => ['_' => 'peerUser', 'user_id' => 12], 'top_message' => 121],
                ],
                'messages' => [
                    ['_' => 'message', 'id' => 101, 'date' => 70, 'peer_id' => ['_' => 'peerUser', 'user_id' => 11]],
                    ['_' => 'message', 'id' => 121, 'date' => 60, 'peer_id' => ['_' => 'peerUser', 'user_id' => 12]],
                ],
            ];
        }

        if ($offsetDate === 60 && $offsetId === 121 && $offsetPeer === 12) {
            return [
                'count' => 5,
                'dialogs' => [
                    ['peer' => ['_' => 'peerUser', 'user_id' => 12], 'top_message' => 121],
                    ['peer' => ['_' => 'peerChannel', 'channel_id' => 77], 'top_message' => 770],
                ],
                'messages' => [
                    ['_' => 'message', 'id' => 121, 'date' => 60, 'peer_id' => ['_' => 'peerUser', 'user_id' => 12]],
                    ['_' => 'message', 'id' => 770, 'date' => 50, 'peer_id' => ['_' => 'peerChannel', 'channel_id' => 77]],
                ],
            ];
        }

        throw new RuntimeException('Pagination should advance using the last usable dialog cursor.');
    },
    static fn(array $peer): array => match ($peer['_']) {
        'peerUser' => ['bot_api_id' => $peer['user_id'], 'type' => 'user'],
        'peerChannel' => ['bot_api_id' => '-100' . $peer['channel_id'], 'type' => 'channel'],
    },
    2
);
$assert(count($cursorFallback) === 5, 'Pagination should skip missing-message cursors and continue despite overlapping pages.');

$stalled = false;
try {
    DialogDirectory::fetchAll(
        static function (int $folderId, int $offsetDate, int $offsetId, int|array|string $offsetPeer, int $limit): array {
            if ($folderId !== 0 || $limit !== 1) {
                throw new RuntimeException('Unexpected folder or batch size while testing stalled pagination.');
            }
            if ($offsetDate === 0) {
                if ($offsetId !== 0 || $offsetPeer !== ['_' => 'inputPeerEmpty']) {
                    throw new RuntimeException('The first dialog page should use an empty offset.');
                }
            } elseif ($offsetDate !== 90 || $offsetId !== 909 || $offsetPeer !== 9) {
                throw new RuntimeException('The next dialog page should use the previous page cursor.');
            }

            return [
                'count' => 3,
                'dialogs' => [['peer' => ['_' => 'peerUser', 'user_id' => 9], 'top_message' => 909]],
                'messages' => [['_' => 'message', 'id' => 909, 'date' => 90, 'peer_id' => ['_' => 'peerUser', 'user_id' => 9]]],
            ];
        },
        static fn(): array => ['bot_api_id' => 9, 'type' => 'user', 'User' => ['first_name' => 'Stalled']],
        1
    );
} catch (RuntimeException) {
    $stalled = true;
}
$assert($stalled, 'A repeated Telegram page should stop with a pagination error instead of looping.');

$negativePage = DialogDirectory::filterAndPaginate($dialogs, '', -5, 2);
$assert($negativePage['page'] === 1, 'Negative page numbers should be clamped to the first page.');

$socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if (!is_resource($socket)) {
    throw new RuntimeException('An available local port should be reserved.');
}
$address = stream_socket_get_name($socket, false);
if ($address === false) {
    fclose($socket);
    throw new RuntimeException('The reserved local port should be available.');
}
fclose($socket);
$port = (int) substr((string) strrchr((string) $address, ':'), 1);
$sessionSavePath = session_save_path();
$sessionPathParts = explode(';', is_string($sessionSavePath) ? $sessionSavePath : '');
$sessionDirectory = end($sessionPathParts);
if (!is_string($sessionDirectory) || $sessionDirectory === '') {
    throw new RuntimeException('The PHP session directory should be available for the HTTP test.');
}
$sessionIds = [];
$seedSession = static function (array $dialogs, bool $simulateError = false, int $version = 2) use (&$sessionIds): string {
    $sessionId = 'dialogtest' . bin2hex(random_bytes(12));
    session_id($sessionId);
    if (!session_start()) {
        throw new RuntimeException('A test session should start.');
    }
    $_SESSION = [
        'logged_in' => true,
        'dialogs' => $dialogs,
        'dialogs_version' => $version,
        'simulate_dialog_error' => $simulateError,
    ];
    session_write_close();
    $sessionIds[] = $sessionId;

    return $sessionId;
};
$serverEnvironment = getenv();
if (!is_array($serverEnvironment)) {
    $serverEnvironment = [];
}
$serverEnvironment['TELEGRAM_API_ID'] = '0';
$serverEnvironment['TELEGRAM_API_HASH'] = 'test-hash';
$server = proc_open(
    [PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__) . '/public', __DIR__ . '/support/dialog_directory_router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
    $serverPipes,
    null,
    $serverEnvironment
);
if (!is_resource($server)) {
    throw new RuntimeException('The HTTP test server should start.');
}

try {
    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $connection = @fsockopen('127.0.0.1', $port, $connectionErrorCode, $connectionErrorMessage, 0.1);
        if (is_resource($connection)) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(50000);
    }
    $assert($ready, 'The HTTP server should accept requests.');
    $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
    file_get_contents('http://127.0.0.1:' . $port . '/', false, $context);
    $assert(isset($http_response_header[0]) && str_contains($http_response_header[0], '302'), 'The conversation directory should remain protected from unauthenticated requests.');

    $homeRequest = static function (string $sessionId, string $path = '/') use ($port): array {
        $context = stream_context_create([
            'http' => [
                'header' => 'Cookie: ' . session_name() . '=' . $sessionId,
                'ignore_errors' => true,
                'timeout' => 5,
            ],
        ]);
        $body = file_get_contents('http://127.0.0.1:' . $port . $path, false, $context);

        return [$http_response_header[0] ?? '', $body === false ? '' : $body];
    };

    [$emptyCacheStatus, $emptyCacheBody] = $homeRequest($seedSession([], true));
    $assert(str_contains($emptyCacheStatus, '200'), 'A Telegram loading error should still render the authenticated home page.');
    $assert(str_contains($emptyCacheBody, 'Error loading conversations:'), 'The home page should report a failed conversation load.');
    $assert(str_contains($emptyCacheBody, 'Conversation list unavailable'), 'A failed conversation load should not be presented as a successful empty result.');

    [$cachedStatus, $cachedBody] = $homeRequest($seedSession([
        ['id' => -10077, 'name' => 'Previously cached channel', 'type' => 'channel', 'username' => ''],
    ], true));
    $assert(str_contains($cachedStatus, '200'), 'The home page should render when a refresh fails but a previous snapshot exists.');
    $assert(str_contains($cachedBody, 'Previously cached channel'), 'A failed refresh should preserve the previous conversation snapshot.');
    $assert(str_contains($cachedBody, 'Error loading conversations:'), 'A failed refresh should show its error alongside the previous snapshot.');

    [$reloadedStatus, $reloadedBody] = $homeRequest($seedSession([]));
    $assert(str_contains($reloadedStatus, '200'), 'An outdated empty cache should be refreshed successfully.');
    $assert(str_contains($reloadedBody, 'Reloaded channel'), 'The home page should render conversations returned by the refreshed directory.');
    $assert(str_contains($reloadedBody, 'href="/media?chat=-1001234567890"'), 'The refreshed channel should link to its media page using the numeric Telegram ID.');
    $assert(str_contains($reloadedBody, 'name="chat"'), 'The manual conversation form should remain available.');

    $presentationDialogs = [];
    for ($index = 1; $index <= 26; $index++) {
        $presentationDialogs[] = [
            'id' => 1000 + $index,
            'name' => $index === 1 ? '<img src=x onerror=alert(1)> Paged conversation 1' : 'Paged conversation ' . $index,
            'type' => $index % 2 === 0 ? 'group' : 'channel',
            'username' => $index === 1 ? 'sample_channel' : '',
        ];
    }
    $presentationSession = $seedSession($presentationDialogs, false, 3);
    [$firstPageStatus, $firstPageBody] = $homeRequest($presentationSession, '/?q=Paged');
    $assert(str_contains($firstPageStatus, '200'), 'A filtered conversation list should render successfully.');
    $assert(str_contains($firstPageBody, 'Page 1 of 2'), 'The conversation list should show its current page and total pages.');
    $assert(str_contains($firstPageBody, '&lt;img src=x onerror=alert(1)&gt;'), 'Telegram conversation names should remain HTML-escaped.');
    $assert(!str_contains($firstPageBody, '<img src=x onerror=alert(1)>'), 'Telegram conversation names should not be rendered as markup.');
    $assert(str_contains($firstPageBody, '@sample_channel'), 'Conversation usernames should be shown beneath their names.');
    $assert(str_contains($firstPageBody, 'href="/?page=2&amp;q=Paged"'), 'Pagination should preserve the current search query.');
    $assert(str_contains($firstPageBody, 'href="/?refresh=1&amp;q=Paged"'), 'Refreshing should preserve the current search query.');
    $assert(str_contains($firstPageBody, 'href="/">Clear search'), 'A filtered directory should offer a clear-search link.');
    $assert(str_contains($firstPageBody, 'class="home-layout"') && str_contains($firstPageBody, 'class="card manual-card"'), 'The home page should group the directory and manual access in distinct layout panels.');

    [, $secondPageBody] = $homeRequest($presentationSession, '/?q=Paged&page=2');
    $assert(str_contains($secondPageBody, 'Page 2 of 2'), 'The next conversation page should render its page number.');
    $assert(str_contains($secondPageBody, 'Paged conversation 26'), 'The final conversation should appear on the second page.');
    $assert(str_contains($secondPageBody, 'href="/?page=1&amp;q=Paged"'), 'The previous-page link should preserve the current search query.');

    [, $noMatchesBody] = $homeRequest($presentationSession, '/?q=NotFound');
    $assert(str_contains($noMatchesBody, 'No matching conversations'), 'A search with no results should have a distinct empty state.');
    $assert(str_contains($noMatchesBody, 'href="/">Clear search'), 'The no-results state should provide a way to reset the search.');

    [$mediaStatus, $mediaBody] = $homeRequest($presentationSession, '/media?chat=%40sample%26channel&type=video');
    $assert(str_contains($mediaStatus, '200'), 'An authenticated media page should render successfully.');
    $assert(str_contains($mediaBody, 'Media in @sample&amp;channel'), 'Chat identifiers should be escaped in the media heading.');
    $assert(str_contains($mediaBody, '&lt;video &amp; clip&gt;.mp4'), 'Media filenames should be escaped by the template.');
    $assert(!str_contains($mediaBody, '<video & clip>.mp4'), 'Media filenames should not be rendered as markup.');
    $assert(str_contains($mediaBody, 'data-label="Type">video</td>'), 'Document MIME types should still be presented as videos.');
    $assert(str_contains($mediaBody, 'href="/download?chat=%40sample%26channel&amp;id=707"'), 'Media download links should encode chat identifiers.');
    $assert(!str_contains($mediaBody, 'Older messages'), 'No older-media link should be shown once the history is exhausted.');
    $assert(!str_contains($mediaBody, 'photo_706.jpg'), 'The media type filter should exclude photos from a video listing.');

    [$allMediaStatus, $allMediaBody] = $homeRequest($presentationSession, '/media?chat=%40sample%26channel');
    $assert(str_contains($allMediaStatus, '200') && str_contains($allMediaBody, 'photo_706.jpg') && str_contains($allMediaBody, 'id=707'), 'The unfiltered list should show photos and files.');
    $assert(!str_contains($allMediaBody, 'id=705') && !str_contains($allMediaBody, 'id=704') && !str_contains($allMediaBody, 'WebPage'), 'Text messages and link previews should not be listed.');
    $assert(substr_count($allMediaBody, 'data-label="Type">') === 2, 'Only the two downloadable messages should be listed.');

    [$noChatStatus, $noChatBody] = $homeRequest($presentationSession, '/media');
    $assert(str_contains($noChatStatus, '200') && str_contains($noChatBody, 'No chat selected.'), 'The media template should handle a missing chat selection.');
} finally {
    proc_terminate($server);
    proc_close($server);
    foreach ($sessionIds as $sessionId) {
        $sessionFile = rtrim($sessionDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sess_' . $sessionId;
        if (is_file($sessionFile)) {
            unlink($sessionFile);
        }
    }
}

fwrite(STDOUT, "Dialog directory tests passed.\n");
