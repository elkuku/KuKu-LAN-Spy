<?php

declare(strict_types=1);

namespace LanSpy\Util;

final class Ip
{
    /** Compare two IP addresses numerically, IPv4 before IPv6; for usort(). */
    public static function compare(string $left, string $right): int
    {
        $left = inet_pton($left);
        $right = inet_pton($right);
        return [strlen($left), $left] <=> [strlen($right), $right];
    }
}
