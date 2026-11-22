<?php

declare(strict_types=1);

namespace claserre9;

use Closure;
use Throwable;

/**
 * Turns MadelineProto messages into the stable, documented records of messages.jsonl.
 *
 * MadelineProto gives peers as Bot API IDs (users > 0, groups < 0, channels -100…). Records keep those IDs,
 * use ISO 8601 dates, and keep 64-bit Telegram IDs that JavaScript cannot represent exactly as strings.
 */
final class MessageJson
{
    /** Offset between a channel's Bot API ID (-100…) and the ID used in t.me/c/ links. */
    private const CHANNEL_ID_OFFSET = 1000000000000;

    /**
     * @param array{id: int, type: string, username?: string, title?: string} $chat The exported chat.
     * @param array{id: int, name: string, username?: string}|null $self The logged-in account, sender of outgoing messages.
     * @param (Closure(array<string, mixed>): string)|null $fileName Name of the file a message's media is saved as by the
     *        download command, or null to leave "file" out.
     */
    public function __construct(
        private readonly array $chat,
        private readonly ?array $self = null,
        private readonly ?Closure $fileName = null,
    ) {
    }

    /**
     * Senders and chats mentioned in a messages.getHistory result, by Bot API ID.
     *
     * @param array<string, mixed> $history
     * @return array<int, array{id: int, name: string, username?: string}>
     */
    public static function peersFrom(array $history): array
    {
        $peers = [];
        foreach (['users', 'chats'] as $key) {
            foreach (is_array($history[$key] ?? null) ? $history[$key] : [] as $peer) {
                if (!is_array($peer) || !is_int($peer['id'] ?? null)) {
                    continue;
                }
                $name = $key === 'users'
                    ? trim(self::string($peer['first_name'] ?? null) . ' ' . self::string($peer['last_name'] ?? null))
                    : self::string($peer['title'] ?? null);
                if (($peer['deleted'] ?? false) === true && $name === '') {
                    $name = 'Deleted Account';
                }
                $record = ['id' => $peer['id'], 'name' => $name];
                if (self::string($peer['username'] ?? null) !== '') {
                    $record['username'] = self::string($peer['username']);
                }
                $peers[$peer['id']] = $record;
            }
        }

        return $peers;
    }

    /**
     * The record of one message, or null for an empty (deleted) message.
     *
     * @param array<string, mixed> $message
     * @param array<int, array{id: int, name: string, username?: string}> $peers Known senders and chats (see peersFrom()).
     * @return array<string, mixed>|null
     */
    public function convert(array $message, array $peers): ?array
    {
        $kind = $message['_'] ?? null;
        $id = $message['id'] ?? null;
        if (!in_array($kind, ['message', 'messageService'], true) || !is_int($id)) {
            return null;
        }

        $chat = ['id' => $this->chat['id']];
        foreach (['username', 'title'] as $key) {
            if (($this->chat[$key] ?? '') !== '') {
                $chat[$key] = $this->chat[$key];
            }
        }
        $record = ['id' => $id, 'chat' => $chat];
        $link = $this->link($id);
        if ($link !== null) {
            $record['link'] = $link;
        }
        $record['date'] = self::date($message['date'] ?? null);
        if (is_int($message['edit_date'] ?? null) && ($message['edit_hide'] ?? false) !== true) {
            $record['edited'] = self::date($message['edit_date']);
        }

        $from = $this->sender($message, $peers);
        if ($from !== null) {
            $record['from'] = $from;
        }
        if (self::string($message['post_author'] ?? null) !== '') {
            $record['author'] = self::string($message['post_author']);
        }

        if ($kind === 'messageService') {
            $action = is_array($message['action'] ?? null) ? $message['action'] : [];
            $record['service'] = ['type' => self::typeName($action['_'] ?? null, 'messageAction')] + self::scalars($action);
            return $record;
        }

        $record['text'] = self::string($message['message'] ?? null);
        $entities = self::entities($message['entities'] ?? null);
        if ($entities !== []) {
            $record['entities'] = $entities;
        }

        $reply = self::reply($message['reply_to'] ?? null);
        if ($reply !== null) {
            $record['reply_to'] = $reply;
        }
        $forward = self::forward($message['fwd_from'] ?? null, $peers);
        if ($forward !== null) {
            $record['forwarded_from'] = $forward;
        }
        if (is_int($message['via_bot_id'] ?? null)) {
            $record['via_bot'] = $peers[$message['via_bot_id']] ?? ['id' => $message['via_bot_id']];
        }
        if (is_int($message['grouped_id'] ?? null)) {
            $record['grouped_id'] = (string) $message['grouped_id'];
        }
        if (is_int($message['views'] ?? null)) {
            $record['views'] = $message['views'];
        }
        if (($message['pinned'] ?? false) === true) {
            $record['pinned'] = true;
        }

        $media = $this->media($message);
        if ($media !== null) {
            $record['media'] = $media;
        }

        return $record;
    }

    /**
     * One JSON Lines line (with its trailing newline). "id" comes first, so tools can read it without decoding.
     *
     * @param array<string, mixed> $record
     */
    public static function line(array $record): string
    {
        return json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
    }

    private function link(int $id): ?string
    {
        if (($this->chat['username'] ?? '') !== '') {
            return 'https://t.me/' . $this->chat['username'] . '/' . $id;
        }
        if (in_array($this->chat['type'], ['channel', 'supergroup'], true) && $this->chat['id'] < -self::CHANNEL_ID_OFFSET) {
            return 'https://t.me/c/' . (-$this->chat['id'] - self::CHANNEL_ID_OFFSET) . '/' . $id;
        }

        // Private chats and basic groups have no message links.
        return null;
    }

    /**
     * @param array<string, mixed> $message
     * @param array<int, array{id: int, name: string, username?: string}> $peers
     * @return array{id: int, name: string, username?: string}|null
     */
    private function sender(array $message, array $peers): ?array
    {
        $fromId = $message['from_id'] ?? null;
        if (!is_int($fromId)) {
            if (($message['out'] ?? false) === true && $this->self !== null) {
                return $this->self;
            }
            // Private chats and channel posts leave the sender out: it is the chat itself.
            $fromId = $message['peer_id'] ?? null;
        }
        if (!is_int($fromId)) {
            return null;
        }
        if ($this->self !== null && $fromId === $this->self['id']) {
            return $this->self;
        }

        return $peers[$fromId] ?? ['id' => $fromId, 'name' => ''];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function entities(mixed $entities): array
    {
        $records = [];
        foreach (is_array($entities) ? $entities : [] as $entity) {
            if (!is_array($entity) || !is_int($entity['offset'] ?? null) || !is_int($entity['length'] ?? null)) {
                continue;
            }
            $record = ['type' => self::typeName($entity['_'] ?? null, 'messageEntity'), 'offset' => $entity['offset'], 'length' => $entity['length']];
            $records[] = $record + self::scalars($entity);
        }

        return $records;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function reply(mixed $reply): ?array
    {
        if (!is_array($reply)) {
            return null;
        }
        if (($reply['_'] ?? null) === 'messageReplyStoryHeader') {
            return is_int($reply['story_id'] ?? null) ? ['story_id' => $reply['story_id']] + (is_int($reply['peer'] ?? null) ? ['chat_id' => $reply['peer']] : []) : null;
        }

        $record = [];
        if (is_int($reply['reply_to_msg_id'] ?? null)) {
            $record['id'] = $reply['reply_to_msg_id'];
        }
        if (is_int($reply['reply_to_peer_id'] ?? null)) {
            $record['chat_id'] = $reply['reply_to_peer_id'];
        }
        if (is_int($reply['reply_to_top_id'] ?? null)) {
            $record['top_id'] = $reply['reply_to_top_id'];
        }
        if (self::string($reply['quote_text'] ?? null) !== '') {
            $record['quote'] = self::string($reply['quote_text']);
        }

        return $record === [] ? null : $record;
    }

    /**
     * @param array<int, array{id: int, name: string, username?: string}> $peers
     * @return array<string, mixed>|null
     */
    private static function forward(mixed $forward, array $peers): ?array
    {
        if (!is_array($forward)) {
            return null;
        }

        $record = [];
        if (is_int($forward['from_id'] ?? null)) {
            $record = $peers[$forward['from_id']] ?? ['id' => $forward['from_id'], 'name' => ''];
        } elseif (self::string($forward['from_name'] ?? null) !== '') {
            // The original sender hides their account: only their name is known.
            $record['name'] = self::string($forward['from_name']);
        }
        if (self::string($forward['post_author'] ?? null) !== '') {
            $record['author'] = self::string($forward['post_author']);
        }
        if (is_int($forward['channel_post'] ?? null)) {
            $record['message_id'] = $forward['channel_post'];
        }
        if (is_int($forward['date'] ?? null)) {
            $record['date'] = self::date($forward['date']);
        }

        return $record === [] ? null : $record;
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>|null
     */
    private function media(array $message): ?array
    {
        $media = $message['media'] ?? null;
        if (!is_array($media)) {
            return null;
        }

        $record = match ($media['_'] ?? null) {
            'messageMediaPhoto' => self::photo($media),
            'messageMediaDocument' => self::document($media),
            'messageMediaWebPage' => self::webPage($media),
            'messageMediaGeo' => ['type' => 'location'] + self::geo($media['geo'] ?? null),
            'messageMediaGeoLive' => ['type' => 'live_location'] + self::geo($media['geo'] ?? null),
            'messageMediaVenue' => ['type' => 'venue'] + self::geo($media['geo'] ?? null) + self::scalars($media, ['title', 'address']),
            'messageMediaContact' => ['type' => 'contact'] + self::scalars($media, ['first_name', 'last_name', 'phone_number', 'user_id']),
            'messageMediaPoll' => self::poll($media),
            'messageMediaDice' => ['type' => 'dice', 'emoji' => self::string($media['emoticon'] ?? null)] + self::scalars($media, ['value']),
            default => ['type' => self::typeName($media['_'] ?? null, 'messageMedia')],
        };
        if (($media['spoiler'] ?? false) === true) {
            $record['spoiler'] = true;
        }
        if (is_int($media['ttl_seconds'] ?? null)) {
            // Self-destructing media: Telegram no longer serves it once viewed.
            $record['ttl_seconds'] = $media['ttl_seconds'];
        }

        if ($this->fileName !== null && MediaType::matches($message, '')) {
            try {
                $record = array_slice($record, 0, 1, true) + ['file' => ($this->fileName)($message)] + $record;
            } catch (Throwable) {
                // Media Telegram no longer describes (expired or deleted): keep the record without a file name.
            }
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $media
     * @return array<string, mixed>
     */
    private static function photo(array $media): array
    {
        $record = ['type' => 'photo'];
        $photo = is_array($media['photo'] ?? null) ? $media['photo'] : [];
        $largest = null;
        foreach (is_array($photo['sizes'] ?? null) ? $photo['sizes'] : [] as $size) {
            if (is_array($size) && is_int($size['w'] ?? null) && is_int($size['h'] ?? null)
                && ($largest === null || $size['w'] * $size['h'] > $largest['w'] * $largest['h'])) {
                $largest = $size;
            }
        }
        if ($largest !== null) {
            $bytes = match ($largest['_'] ?? null) {
                'photoSize' => is_int($largest['size'] ?? null) ? $largest['size'] : null,
                'photoSizeProgressive' => is_array($largest['sizes'] ?? null) && $largest['sizes'] !== [] ? (int) max($largest['sizes']) : null,
                default => null,
            };
            if ($bytes !== null) {
                $record['size'] = $bytes;
            }
            $record['width'] = $largest['w'];
            $record['height'] = $largest['h'];
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $media
     * @return array<string, mixed>
     */
    private static function document(array $media): array
    {
        $document = is_array($media['document'] ?? null) ? $media['document'] : [];
        $attributes = [];
        foreach (is_array($document['attributes'] ?? null) ? $document['attributes'] : [] as $attribute) {
            if (is_array($attribute) && is_string($attribute['_'] ?? null)) {
                $attributes[$attribute['_']] = $attribute;
            }
        }
        $video = $attributes['documentAttributeVideo'] ?? null;
        $audio = $attributes['documentAttributeAudio'] ?? null;

        $type = match (true) {
            isset($attributes['documentAttributeSticker']) => 'sticker',
            isset($attributes['documentAttributeAnimated']) => 'gif',
            $video !== null && ($video['round_message'] ?? false) === true => 'video_note',
            $video !== null => 'video',
            $audio !== null && ($audio['voice'] ?? false) === true => 'voice',
            $audio !== null => 'audio',
            default => 'document',
        };

        $record = ['type' => $type];
        $name = self::string($attributes['documentAttributeFilename']['file_name'] ?? null);
        if ($name !== '') {
            $record['name'] = $name;
        }
        if (self::string($document['mime_type'] ?? null) !== '') {
            $record['mime'] = self::string($document['mime_type']);
        }
        if (is_int($document['size'] ?? null)) {
            $record['size'] = $document['size'];
        }
        $duration = $video['duration'] ?? $audio['duration'] ?? null;
        if (is_int($duration) || is_float($duration)) {
            $record['duration'] = $duration;
        }
        $dimensions = $video ?? $attributes['documentAttributeImageSize'] ?? null;
        if (is_int($dimensions['w'] ?? null) && is_int($dimensions['h'] ?? null)) {
            $record['width'] = $dimensions['w'];
            $record['height'] = $dimensions['h'];
        }
        if ($type === 'audio') {
            $record += self::scalars($audio, ['title', 'performer']);
        }
        if ($type === 'sticker' && self::string($attributes['documentAttributeSticker']['alt'] ?? null) !== '') {
            $record['emoji'] = self::string($attributes['documentAttributeSticker']['alt']);
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $media
     * @return array<string, mixed>
     */
    private static function webPage(array $media): array
    {
        $page = is_array($media['webpage'] ?? null) ? $media['webpage'] : [];

        return ['type' => 'webpage'] + self::scalars($page, ['url', 'site_name', 'title', 'description']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function geo(mixed $geo): array
    {
        if (!is_array($geo) || !is_numeric($geo['lat'] ?? null) || !is_numeric($geo['long'] ?? null)) {
            return [];
        }

        return ['latitude' => (float) $geo['lat'], 'longitude' => (float) $geo['long']];
    }

    /**
     * @param array<string, mixed> $media
     * @return array<string, mixed>
     */
    private static function poll(array $media): array
    {
        $poll = is_array($media['poll'] ?? null) ? $media['poll'] : [];
        $voters = [];
        foreach (is_array($media['results']['results'] ?? null) ? $media['results']['results'] : [] as $result) {
            if (is_array($result) && isset($result['option']) && is_int($result['voters'] ?? null)) {
                $voters[self::bytes($result['option'])] = $result['voters'];
            }
        }

        $answers = [];
        foreach (is_array($poll['answers'] ?? null) ? $poll['answers'] : [] as $answer) {
            if (!is_array($answer)) {
                continue;
            }
            $record = ['text' => self::text($answer['text'] ?? null)];
            $option = isset($answer['option']) ? self::bytes($answer['option']) : null;
            if ($option !== null && isset($voters[$option])) {
                $record['voters'] = $voters[$option];
            }
            $answers[] = $record;
        }

        $record = ['type' => 'poll', 'question' => self::text($poll['question'] ?? null), 'answers' => $answers];
        if (is_int($media['results']['total_voters'] ?? null)) {
            $record['total_voters'] = $media['results']['total_voters'];
        }
        foreach (['closed', 'quiz', 'multiple_choice'] as $flag) {
            if (($poll[$flag] ?? false) === true) {
                $record[$flag] = true;
            }
        }

        return $record;
    }

    /**
     * Plain values of a Telegram object: true flags, numbers, strings and lists of them.
     * Nested objects, binary data and internal fields are left out.
     *
     * @param array<string, mixed> $object
     * @param list<string>|null $only Keep only these keys.
     * @return array<string, mixed>
     */
    private static function scalars(array $object, ?array $only = null): array
    {
        $values = [];
        foreach ($object as $key => $value) {
            if (in_array($key, ['_', 'flags', 'flags2', 'offset', 'length', 'access_hash', 'hash'], true)
                || ($only !== null && !in_array($key, $only, true))) {
                continue;
            }
            if ($value === true || is_int($value) || is_float($value) || (is_string($value) && $value !== '')) {
                $values[$key] = $value;
            } elseif (is_array($value) && $value !== [] && array_is_list($value)
                && array_filter($value, static fn (mixed $item): bool => !is_int($item) && !is_string($item)) === []) {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /** "messageEntityTextUrl" → "text_url". */
    private static function typeName(mixed $constructor, string $prefix): string
    {
        $name = is_string($constructor) && str_starts_with($constructor, $prefix) ? substr($constructor, strlen($prefix)) : self::string($constructor);
        $name = strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $name));

        return $name === '' ? 'unknown' : $name;
    }

    /** Text of a plain string or a textWithEntities object (poll questions and answers). */
    private static function text(mixed $text): string
    {
        return is_array($text) ? self::string($text['text'] ?? null) : self::string($text);
    }

    private static function bytes(mixed $value): string
    {
        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function date(mixed $timestamp): ?string
    {
        return is_int($timestamp) ? gmdate('Y-m-d\TH:i:s\Z', $timestamp) : null;
    }
}
