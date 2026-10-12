<?php

declare(strict_types=1);

namespace LanSpy\Util;

final class Json
{
    /** Print $data as pretty JSON with a two-space indent, like the reports have always had. */
    public static function write(mixed $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        echo preg_replace_callback('/^(?: {4})+/m', fn(array $indent): string => str_repeat(' ', intdiv(strlen($indent[0]), 2)), $json), "\n";
    }
}
