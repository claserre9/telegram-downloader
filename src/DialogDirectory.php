<?php

namespace claserre9;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class DialogDirectory
{
    /**
     * Fetch and normalize dialogs from the main and archived folders.
     *
     * @param callable(int, int, int, int|array<string, string>|string, int): array<string, mixed> $fetchPage
     * @param callable(mixed): mixed $getInfo
     * @param callable|null $resolveMessagePeerId
     * @return list<array{id: int|string, name: string, type: string, username: string}>
     */
    public static function fetchAll(callable $fetchPage, callable $getInfo, int $batchSize = 100, ?callable $resolveMessagePeerId = null): array
    {
        if ($batchSize < 1 || $batchSize > 100) {
            throw new InvalidArgumentException('Dialog batch size must be between 1 and 100.');
        }

        $directory = [];
        foreach ([0, 1] as $folderId) {
            $offsetDate = 0;
            $offsetId = 0;
            $offsetPeer = ['_' => 'inputPeerEmpty'];
            $fetched = 0;
            $seenOffsets = [];
            $seenDialogs = [];

            while (true) {
                $result = $fetchPage($folderId, $offsetDate, $offsetId, $offsetPeer, $batchSize);
                if (!isset($result['dialogs']) || !is_array($result['dialogs'])) {
                    throw new RuntimeException('Telegram returned an invalid dialogs response.');
                }

                $batch = array_values($result['dialogs']);
                $messages = is_array($result['messages'] ?? null) ? $result['messages'] : [];
                $knownTotal = isset($result['count']) && is_numeric($result['count'])
                    ? (int) $result['count']
                    : null;

                if ($batch === [] && $knownTotal !== null && $fetched < $knownTotal) {
                    throw new RuntimeException('Telegram returned an incomplete dialogs page.');
                }

                $newDialogs = 0;
                foreach ($batch as $dialog) {
                    if (!self::isConversationDialog($dialog)) {
                        continue;
                    }

                    $entry = self::normalize($dialog['peer'], $getInfo);
                    $dialogKey = (string) $entry['id'] !== '0'
                        ? 'id:' . (string) $entry['id']
                        : self::dialogKey($dialog);
                    if (!isset($seenDialogs[$dialogKey])) {
                        $seenDialogs[$dialogKey] = true;
                        $newDialogs++;
                    }

                    if ((string) $entry['id'] !== '0') {
                        $directory[(string) $entry['id']] = $entry;
                    }
                }

                $fetched += $newDialogs;

                if ($batch === [] || ($knownTotal !== null && $fetched >= $knownTotal)) {
                    break;
                }
                if ($knownTotal === null && count($batch) < $batchSize) {
                    break;
                }

                $cursor = null;
                for ($index = count($batch) - 1; $index >= 0; $index--) {
                    $dialog = $batch[$index];
                    if (!self::isConversationDialog($dialog)) {
                        continue;
                    }

                    $peer = $dialog['peer'];
                    $messageId = (int) ($dialog['top_message'] ?? 0);
                    if ($messageId <= 0) {
                        continue;
                    }

                    $entry = self::normalize($peer, $getInfo);
                    $message = self::findTopMessage($messages, $peer, $entry['id'], $messageId, $resolveMessagePeerId);
                    $date = (int) ($message['date'] ?? 0);
                    if ($date <= 0) {
                        continue;
                    }

                    if ((string) $entry['id'] === '0') {
                        continue;
                    }

                    $cursor = [
                        'date' => $date,
                        'message_id' => $messageId,
                        'entry' => $entry,
                    ];
                    break;
                }

                if ($cursor === null) {
                    throw new RuntimeException('Telegram returned dialogs without a usable pagination offset.');
                }

                $offsetKey = $folderId . ':' . $cursor['date'] . ':' . $cursor['message_id'] . ':' . $cursor['entry']['id'];
                if (isset($seenOffsets[$offsetKey])) {
                    throw new RuntimeException('Telegram dialog pagination stopped advancing.');
                }
                $seenOffsets[$offsetKey] = true;
                $offsetDate = $cursor['date'];
                $offsetId = $cursor['message_id'];
                $offsetPeer = $cursor['entry']['id'];
            }
        }

        return array_values($directory);
    }

    /**
     * Filter and paginate a previously fetched directory.
     *
     * @param array<int, array{id: int|string, name: string, type: string, username?: string}> $dialogs
     * @return array{items: list<array{id: int|string, name: string, type: string, username?: string}>, total: int, page: int, pages: int}
     */
    public static function filterAndPaginate(array $dialogs, string $query, int $page, int $perPage = 25): array
    {
        $perPage = max(1, min(100, $perPage));
        $query = trim($query);
        $filtered = array_values(array_filter($dialogs, static function (array $dialog) use ($query): bool {
            if ($query === '') {
                return true;
            }

            $searchable = implode(' ', [
                $dialog['name'],
                $dialog['type'],
                (string) ($dialog['username'] ?? ''),
                (string) $dialog['id'],
            ]);

            return stripos($searchable, $query) !== false;
        }));

        $total = count($filtered);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));

        return [
            'items' => array_slice($filtered, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ];
    }

    /**
     * @param mixed $peer
     * @param callable(mixed): mixed $getInfo
     * @return array{id: int|string, name: string, type: string, username: string}
     */
    private static function normalize(mixed $peer, callable $getInfo): array
    {
        try {
            $info = $getInfo($peer);
        } catch (Throwable) {
            $info = [];
        }

        if (!is_array($info)) {
            $info = [];
        }

        $id = $info['bot_api_id'] ?? self::fallbackId($peer);
        if (!is_int($id) && !(is_string($id) && preg_match('/^-?\d+$/', $id))) {
            $id = self::fallbackId($peer);
        }
        if ((string) $id === '0') {
            $id = self::fallbackId($peer);
        }

        $peerData = $info['User'] ?? $info['Chat'] ?? [];
        if (!is_array($peerData)) {
            $peerData = [];
        }
        $name = trim(implode(' ', array_filter([
            trim((string) ($peerData['first_name'] ?? '')),
            trim((string) ($peerData['last_name'] ?? '')),
        ], static fn(string $part): bool => $part !== '')));
        if ($name === '') {
            $name = trim((string) ($peerData['title'] ?? ''));
        }
        $username = trim((string) ($peerData['username'] ?? ''));
        if ($name === '') {
            $name = $username !== '' ? '@' . $username : 'Chat ' . $id;
        }

        return [
            'id' => $id,
            'name' => $name,
            'type' => (string) ($info['type'] ?? self::fallbackType($peer, $id)),
            'username' => $username,
        ];
    }

    /**
     * @param array<int, mixed> $messages
     * @param mixed $peer
     * @param int|string $peerId
     * @param callable|null $resolveMessagePeerId
     * @return array<string, mixed>|null
     */
    private static function findTopMessage(array $messages, mixed $peer, int|string $peerId, int $messageId, ?callable $resolveMessagePeerId): ?array
    {
        $peerKey = is_array($peer) ? self::peerKey($peer) : null;
        foreach ($messages as $message) {
            if (!is_array($message) || (int) ($message['id'] ?? 0) !== $messageId) {
                continue;
            }

            $messagePeer = $message['peer_id'] ?? $message['to_id'] ?? null;
            if ($peerKey !== null && is_array($messagePeer) && self::peerKey($messagePeer) === $peerKey) {
                return $message;
            }

            if ($resolveMessagePeerId !== null) {
                try {
                    $resolvedPeerId = $resolveMessagePeerId($message);
                } catch (Throwable) {
                    $resolvedPeerId = null;
                }
                if ($resolvedPeerId !== null && (string) $resolvedPeerId === (string) $peerId) {
                    return $message;
                }
                continue;
            }

            if ($peerKey === null && $messagePeer === null) {
                return $message;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $peer
     */
    private static function peerKey(array $peer): ?string
    {
        if (isset($peer['peer']) && is_array($peer['peer'])) {
            return self::peerKey($peer['peer']);
        }

        $fields = [
            'peerUser' => 'user_id',
            'messagePeerUser' => 'user_id',
            'user' => 'id',
            'peerChat' => 'chat_id',
            'messagePeerChat' => 'chat_id',
            'chat' => 'id',
            'peerChannel' => 'channel_id',
            'messagePeerChannel' => 'channel_id',
            'channel' => 'id',
        ];
        $field = $fields[$peer['_'] ?? ''] ?? null;

        return $field !== null && isset($peer[$field])
            ? $peer['_'] . ':' . $peer[$field]
            : null;
    }

    private static function dialogKey(mixed $dialog): string
    {
        if (is_array($dialog) && self::isPeer($dialog['peer'] ?? null)) {
            $peer = $dialog['peer'];
            if (is_array($peer)) {
                $peerKey = self::peerKey($peer);
                if ($peerKey !== null) {
                    return $peerKey;
                }
            } else {
                return 'id:' . (string) $peer;
            }
        }

        return 'other:' . hash('sha256', serialize($dialog));
    }

    /**
     * @param mixed $peer
     */
    private static function fallbackId(mixed $peer): int|string
    {
        if (is_int($peer) || (is_string($peer) && preg_match('/^-?\d+$/', $peer) === 1)) {
            return $peer;
        }
        if (is_array($peer) && is_array($peer['peer'] ?? null)) {
            return self::fallbackId($peer['peer']);
        }

        return match ($peer['_'] ?? '') {
            'peerUser' => (int) ($peer['user_id'] ?? 0),
            'peerChat' => -(int) ($peer['chat_id'] ?? 0),
            'peerChannel' => '-100' . (string) ($peer['channel_id'] ?? 0),
            default => 0,
        };
    }

    /**
     * @param mixed $peer
     * @param int|string $id
     */
    private static function fallbackType(mixed $peer, int|string $id): string
    {
        if (is_int($peer) || is_string($peer)) {
            $idString = (string) $id;
            if (str_starts_with($idString, '-100')) {
                return 'channel';
            }

            return (int) $idString < 0 ? 'group' : 'user';
        }
        if (is_array($peer) && is_array($peer['peer'] ?? null)) {
            return self::fallbackType($peer['peer'], $id);
        }

        return match ($peer['_'] ?? '') {
            'peerUser' => 'user',
            'peerChat' => 'group',
            'peerChannel' => 'channel',
            default => 'chat',
        };
    }

    private static function isPeer(mixed $peer): bool
    {
        if (is_int($peer) || (is_string($peer) && preg_match('/^-?\d+$/', $peer) === 1)) {
            return true;
        }
        if (!is_array($peer)) {
            return false;
        }
        if (is_array($peer['peer'] ?? null)) {
            return self::isPeer($peer['peer']);
        }

        return in_array($peer['_'] ?? '', [
            'peerUser', 'peerChat', 'peerChannel',
            'messagePeerUser', 'messagePeerChat', 'messagePeerChannel',
            'inputPeerUser', 'inputPeerChat', 'inputPeerChannel',
        ], true);
    }

    private static function isConversationDialog(mixed $dialog): bool
    {
        return is_array($dialog)
            && ($dialog['_'] ?? '') !== 'dialogFolder'
            && self::isPeer($dialog['peer'] ?? null);
    }
}
