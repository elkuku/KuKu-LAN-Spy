<?php

declare(strict_types=1);

namespace LanSpy\Util;

final class Text
{
    /** Python-style truthiness for optional strings: null and '' are missing, '0' is a value. */
    public static function present(?string $value): bool
    {
        return $value !== null && $value !== '';
    }

    /** Treat '' like a missing value, as the scan data does. */
    public static function orNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    public static function containsAny(string $haystack, string ...$needles): bool
    {
        return array_any($needles, fn(string $needle): bool => str_contains($haystack, $needle));
    }
}
