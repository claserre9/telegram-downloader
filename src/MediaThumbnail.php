<?php

declare(strict_types=1);

namespace claserre9;

use danog\MadelineProto\Tools;
use Throwable;

/**
 * Thumbnails Telegram attaches to photos and documents (videos, GIFs, images sent as files).
 */
final class MediaThumbnail
{
    /** Preferred thumbnail sizes: "m" is 320 px, "s" 100 px, "x" 800 px. */
    private const PREFERRED_TYPES = ['m', 's', 'x'];

    /**
     * The thumbnail to download for a message, or null if it has none (audio, most files).
     *
     * @param array<string, mixed> $message
     * @return array{type: string, size: int}|null
     */
    public static function pick(array $message): ?array
    {
        $byType = [];
        foreach (self::sizes($message) as $size) {
            $kind = $size['_'] ?? null;
            $type = $size['type'] ?? null;
            if (!is_string($type) || !in_array($kind, ['photoSize', 'photoSizeProgressive'], true)) {
                continue;
            }
            $bytes = $kind === 'photoSize'
                ? (int) ($size['size'] ?? 0)
                : (int) (is_array($size['sizes'] ?? null) && $size['sizes'] !== [] ? max($size['sizes']) : 0);
            $byType[$type] = ['type' => $type, 'size' => $bytes];
        }

        foreach (self::PREFERRED_TYPES as $type) {
            if (isset($byType[$type])) {
                return $byType[$type];
            }
        }

        return null;
    }

    /**
     * The tiny blurred preview embedded in the message, as a data: URI shown while the thumbnail loads.
     *
     * @param array<string, mixed> $message
     */
    public static function placeholder(array $message): ?string
    {
        foreach (self::sizes($message) as $size) {
            if (($size['_'] ?? null) !== 'photoStrippedSize' || !isset($size['bytes'])) {
                continue;
            }
            try {
                $jpeg = Tools::inflateStripped((string) $size['bytes']);
            } catch (Throwable) {
                return null;
            }

            return 'data:image/jpeg;base64,' . base64_encode($jpeg);
        }

        return null;
    }

    /**
     * Download info that makes MadelineProto fetch a thumbnail instead of the full file.
     *
     * @param array<string, mixed> $downloadInfo Result of getDownloadInfo() for the message.
     * @param array{type: string, size: int} $thumbnail
     * @return array<string, mixed>
     */
    public static function downloadInfo(array $downloadInfo, array $thumbnail): array
    {
        $location = is_array($downloadInfo['InputFileLocation'] ?? null) ? $downloadInfo['InputFileLocation'] : [];
        $location['thumb_size'] = $thumbnail['type'];
        $downloadInfo['InputFileLocation'] = $location;
        $downloadInfo['size'] = $thumbnail['size'];
        $downloadInfo['ext'] = '.jpg';
        $downloadInfo['mime'] = 'image/jpeg';

        return $downloadInfo;
    }

    /**
     * @param array<string, mixed> $message
     * @return list<array<string, mixed>>
     */
    private static function sizes(array $message): array
    {
        $media = is_array($message['media'] ?? null) ? $message['media'] : [];
        $sizes = match ($media['_'] ?? null) {
            'messageMediaPhoto' => $media['photo']['sizes'] ?? [],
            'messageMediaDocument' => $media['document']['thumbs'] ?? [],
            default => [],
        };

        return is_array($sizes) ? array_values(array_filter($sizes, 'is_array')) : [];
    }
}
