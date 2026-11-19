<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use claserre9\MediaThumbnail;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$stripped = ['_' => 'photoStrippedSize', 'type' => 'i', 'bytes' => "\x01\x28\x1e" . 'jpeg-body'];
$photo = ['media' => ['_' => 'messageMediaPhoto', 'photo' => ['sizes' => [
    $stripped,
    ['_' => 'photoSize', 'type' => 'm', 'w' => 320, 'h' => 240, 'size' => 31571],
    ['_' => 'photoSize', 'type' => 'x', 'w' => 800, 'h' => 600, 'size' => 128268],
    ['_' => 'photoSizeProgressive', 'type' => 'w', 'w' => 1448, 'h' => 1086, 'sizes' => [1000, 307625]],
]]]];
$video = ['media' => ['_' => 'messageMediaDocument', 'document' => ['mime_type' => 'video/mp4', 'thumbs' => [
    $stripped,
    ['_' => 'photoSize', 'type' => 'm', 'w' => 320, 'h' => 240, 'size' => 28639],
]]]];
$sticker = ['media' => ['_' => 'messageMediaDocument', 'document' => ['thumbs' => [
    ['_' => 'photoPathSize', 'type' => 'j', 'bytes' => 'path'],
    ['_' => 'photoCachedSize', 'type' => 'm', 'bytes' => 'cached'],
]]]];
$progressiveOnly = ['media' => ['_' => 'messageMediaPhoto', 'photo' => ['sizes' => [
    ['_' => 'photoSizeProgressive', 'type' => 'x', 'sizes' => [500, 9000]],
]]]];
$audio = ['media' => ['_' => 'messageMediaDocument', 'document' => ['mime_type' => 'audio/ogg']]];
$webPage = ['media' => ['_' => 'messageMediaWebPage', 'webpage' => ['photo' => ['sizes' => [['_' => 'photoSize', 'type' => 'm', 'size' => 1]]]]]];

// The 320 px size is preferred over the full-size photo.
$assert(MediaThumbnail::pick($photo) === ['type' => 'm', 'size' => 31571], 'Photos should use the 320 px thumbnail.');
$assert(MediaThumbnail::pick($video) === ['type' => 'm', 'size' => 28639], 'Videos should use their document thumbnail.');
$assert(MediaThumbnail::pick($progressiveOnly) === ['type' => 'x', 'size' => 9000], 'Progressive sizes should use their largest step.');
$assert(MediaThumbnail::pick($sticker) === null, 'Cached and vector sizes are not downloadable thumbnails.');
$assert(MediaThumbnail::pick($audio) === null && MediaThumbnail::pick($webPage) === null && MediaThumbnail::pick([]) === null, 'Media without thumbnails should have none.');

// The embedded blurred preview becomes a complete JPEG data URI.
$placeholder = MediaThumbnail::placeholder($photo);
$assert(is_string($placeholder) && str_starts_with($placeholder, 'data:image/jpeg;base64,'), 'The stripped preview should become a data URI.');
$jpeg = base64_decode(substr((string) $placeholder, strlen('data:image/jpeg;base64,')), true);
$assert(is_string($jpeg) && str_starts_with($jpeg, "\xFF\xD8") && str_contains($jpeg, 'jpeg-body') && str_ends_with($jpeg, "\xFF\xD9"), 'The preview should be wrapped in the JPEG header and footer.');
$assert(MediaThumbnail::placeholder($audio) === null && MediaThumbnail::placeholder($sticker) === null, 'Media without a stripped preview should have no placeholder.');

// The download info asks Telegram for the thumbnail, not the full file.
$info = MediaThumbnail::downloadInfo(
    ['InputFileLocation' => ['_' => 'inputDocumentFileLocation', 'id' => 1, 'thumb_size' => ''], 'size' => 50_000_000, 'ext' => '.mp4', 'mime' => 'video/mp4', 'name' => 'clip'],
    ['type' => 'm', 'size' => 28639],
);
$assert($info['InputFileLocation'] === ['_' => 'inputDocumentFileLocation', 'id' => 1, 'thumb_size' => 'm'], 'The file location should request the thumbnail size.');
$assert($info['size'] === 28639 && $info['ext'] === '.jpg' && $info['mime'] === 'image/jpeg' && $info['name'] === 'clip', 'The size and type should describe the thumbnail.');

fwrite(STDOUT, "Media thumbnail tests passed.\n");
