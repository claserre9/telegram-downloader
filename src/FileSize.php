<?php

declare(strict_types=1);

namespace claserre9;

final class FileSize
{
    /**
     * Human-readable size, e.g. "512 B" or "46.25 MB".
     */
    public static function format(int $bytes, int $precision = 2): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $bytes;
        $unit = 0;
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, $precision) . ' ' . $units[$unit];
    }
}
