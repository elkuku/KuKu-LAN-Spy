<?php

declare(strict_types=1);

namespace LanSpy\Build;

use SimpleXMLElement;

/** Helpers for reading nmap's XML output. */
final class NmapXml
{
    public static function first(SimpleXMLElement $element, string $path): ?SimpleXMLElement
    {
        return $element->xpath($path)[0] ?? null;
    }

    public static function attr(?SimpleXMLElement $element, string $name): ?string
    {
        return $element instanceof SimpleXMLElement && isset($element[$name]) ? (string) $element[$name] : null;
    }

    public static function hostIp(SimpleXMLElement $host): ?string
    {
        return self::attr(self::first($host, "address[@addrtype='ipv4']"), 'addr');
    }

    public static function isUp(SimpleXMLElement $host): bool
    {
        return self::attr($host->status[0] ?? null, 'state') === 'up';
    }
}
