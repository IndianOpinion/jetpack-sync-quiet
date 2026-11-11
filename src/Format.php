<?php

declare(strict_types=1);

namespace IndianOpinion\JetpackSyncQuiet;

/**
 * Small formatting helpers for status output.
 */
final class Format
{
    public static function time(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp) . ' UTC';
    }

    public static function duration(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '';
        $seconds = abs($seconds);
        if ($seconds < 60) {
            return $sign . $seconds . ' s';
        }
        if ($seconds < 3600) {
            return sprintf('%s%d min %d s', $sign, intdiv($seconds, 60), $seconds % 60);
        }
        if ($seconds < 86400) {
            return sprintf('%s%d h %d min', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
        }

        return sprintf('%s%d d %d h', $sign, intdiv($seconds, 86400), intdiv($seconds % 86400, 3600));
    }

    public static function yesNo(?bool $value): string
    {
        return $value === null ? 'unknown' : ($value ? 'yes' : 'no');
    }

    public static function raw(mixed $value): string
    {
        if ($value === false || $value === null) {
            return 'missing';
        }
        if ($value === true) {
            return '1';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return gettype($value);
    }
}
