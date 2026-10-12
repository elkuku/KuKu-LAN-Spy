<?php

declare(strict_types=1);

namespace LanSpy\Render;

use DateTimeImmutable;
use Exception;

/** Helpers for the templates. */
final class Html
{
    public static function escape(?string $value): string
    {
        return strtr((string) $value, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#x27;']);
    }

    /** An ISO date as "Y-m-d H:i" in the local timezone; '' for none. */
    public static function formatTime(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '';
        }
        try {
            return (new DateTimeImmutable($iso))->format('Y-m-d H:i');
        } catch (Exception) {
            return $iso;
        }
    }
}
