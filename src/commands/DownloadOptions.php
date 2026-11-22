<?php

declare(strict_types=1);

namespace claserre9\commands;

use claserre9\MediaType;
use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Component\Filesystem\Path;

/**
 * Validated options of the download command.
 */
final class DownloadOptions
{
    private function __construct(
        public readonly string $chat,
        public readonly string $type,
        public readonly string $directory,
        public readonly ?int $limit,
        public readonly ?int $since,
        public readonly bool $rescan,
    ) {
    }

    /**
     * Also used by the message export, which has no --type: $command and $limitUnit only change error messages.
     *
     * @throws InvalidArgumentException With a message for the user if an option is invalid.
     */
    public static function parse(
        mixed $chat,
        mixed $envChat,
        mixed $type,
        mixed $output,
        mixed $limit,
        mixed $since,
        bool $rescan,
        string $projectRoot,
        string $workingDirectory,
        string $command = 'tdm',
        string $limitUnit = 'files',
    ): self {
        $chat = is_string($chat) && trim($chat) !== '' ? trim($chat) : (is_string($envChat) ? trim($envChat) : '');
        if ($chat === '') {
            throw new InvalidArgumentException("Give the chat, e.g. \"$command @my_channel\", or set TELEGRAM_CHANNEL.");
        }

        $type = is_string($type) ? strtolower(trim($type)) : '';
        if ($type !== '' && !in_array($type, MediaType::FILTERS, true)) {
            throw new InvalidArgumentException('--type must be one of: ' . implode(', ', MediaType::FILTERS) . '.');
        }

        $directory = is_string($output) && trim($output) !== ''
            ? Path::makeAbsolute(trim($output), $workingDirectory)
            : Path::join($projectRoot, 'downloads', DownloadMediaCommand::chatFolder($chat));

        $maxFiles = null;
        if ($limit !== null) {
            if (!is_string($limit) || preg_match('/\A[1-9][0-9]{0,8}\z/', trim($limit)) !== 1) {
                throw new InvalidArgumentException("--limit must be a positive number of $limitUnit.");
            }
            $maxFiles = (int) trim($limit);
        }

        $sinceTimestamp = null;
        if ($since !== null) {
            $date = is_string($since) && trim($since) !== '' ? self::parseDate(trim($since)) : null;
            if ($date === null) {
                throw new InvalidArgumentException('--since must be a date such as 2024-01-31 or "7 days ago".');
            }
            $sinceTimestamp = $date->getTimestamp();
        }

        return new self($chat, $type, $directory, $maxFiles, $sinceTimestamp, $rescan);
    }

    private static function parseDate(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
