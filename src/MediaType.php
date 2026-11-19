<?php

declare(strict_types=1);

namespace claserre9;

/**
 * Media types shared by the web interface and the download command.
 */
final class MediaType
{
    /** Types a user can filter on. "document" means any file, including videos and audio sent as files. */
    public const FILTERS = ['photo', 'video', 'document', 'audio'];

    /** Photos and documents (videos, audio, voice notes, GIFs, files). Link previews and polls are not downloadable. */
    public const DOWNLOADABLE = ['messageMediaPhoto', 'messageMediaDocument'];

    /**
     * Whether a message carries downloadable media of the given type ('' = any type).
     *
     * @param array<string, mixed> $message
     */
    public static function matches(array $message, string $filter): bool
    {
        $media = $message['media'] ?? null;
        if (!is_array($media) || !in_array($media['_'] ?? null, self::DOWNLOADABLE, true)) {
            return false;
        }
        if ($filter === '') {
            return true;
        }
        if ($media['_'] === 'messageMediaPhoto') {
            return $filter === 'photo';
        }
        if ($filter === 'document') {
            return true;
        }

        return ($filter === 'video' || $filter === 'audio') && self::of($message) === $filter;
    }

    /**
     * Display type of a message's media: photo, video, audio, document, or the raw Telegram name otherwise.
     *
     * @param array<string, mixed> $message
     */
    public static function of(array $message): string
    {
        $media = is_array($message['media'] ?? null) ? $message['media'] : [];
        $raw = is_string($media['_'] ?? null) ? $media['_'] : '';
        if ($raw === 'messageMediaPhoto') {
            return 'photo';
        }
        if ($raw !== 'messageMediaDocument') {
            return str_replace('messageMedia', '', $raw);
        }

        $mime = $media['document']['mime_type'] ?? '';
        if (is_string($mime) && str_starts_with($mime, 'video/')) {
            return 'video';
        }
        if (is_string($mime) && str_starts_with($mime, 'audio/')) {
            return 'audio';
        }

        return 'document';
    }

    /**
     * Telegram server-side search filter for a type, or null for no filter.
     *
     * @return array{_: string}|null
     */
    public static function searchFilter(string $filter): ?array
    {
        return match ($filter) {
            'photo' => ['_' => 'inputMessagesFilterPhotos'],
            'video' => ['_' => 'inputMessagesFilterVideo'],
            'document' => ['_' => 'inputMessagesFilterDocument'],
            'audio' => ['_' => 'inputMessagesFilterMusic'],
            default => null,
        };
    }
}
