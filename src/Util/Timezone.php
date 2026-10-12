<?php

declare(strict_types=1);

namespace LanSpy\Util;

final class Timezone
{
    /** Use the system timezone (PHP defaults to UTC) so dates carry the local offset. */
    public static function useLocal(): void
    {
        $tz = getenv('TZ') ?: (preg_match('#zoneinfo/(.+)$#', (string) @readlink('/etc/localtime'), $match) ? $match[1] : '');
        if (in_array($tz, timezone_identifiers_list(), true)) {
            date_default_timezone_set($tz);
        }
    }
}
