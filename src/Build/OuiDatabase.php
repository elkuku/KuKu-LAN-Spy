<?php

declare(strict_types=1);

namespace LanSpy\Build;

/** nmap's list of MAC address prefixes and the vendors they belong to. */
final class OuiDatabase
{
    private const FILE = '/usr/share/nmap/nmap-mac-prefixes';

    /** @param array<string, string> $vendors MAC prefix (6 hex digits, upper case) => vendor */
    private function __construct(private readonly array $vendors) {}

    /** An empty database if nmap's list is not installed. */
    public static function load(): self
    {
        $vendors = [];
        $handle = @fopen(self::FILE, 'r');
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                if (!str_starts_with($line, '#')) {
                    $parts = explode(' ', trim($line), 2);
                    $vendors[strtoupper($parts[0])] = $parts[1] ?? '';
                }
            }
            fclose($handle);
        }
        return new self($vendors);
    }

    public function vendor(string $mac): ?string
    {
        return $this->vendors[strtoupper(substr(str_replace(':', '', $mac), 0, 6))] ?? null;
    }
}
