<?php

declare(strict_types=1);

namespace LanSpy\Render;

use LanSpy\Util\CliError;
use LanSpy\Util\Text;

/** Custom device names: one '<mac-or-ip> [type] <name>' per line, '#' lines are comments. */
final class DeviceNames
{
    /** @param array<string, CustomName> $byAddress keyed by lowercase MAC (with colons) or IP */
    private function __construct(private readonly array $byAddress)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** @throws CliError on an unknown device type */
    public static function load(string $path): self
    {
        $byAddress = [];
        foreach (preg_split('/\r\n|\r|\n/', file_get_contents($path)) as $line) {
            $parts = preg_split('/\s+/u', ltrim($line), 2, PREG_SPLIT_NO_EMPTY);
            if (count($parts) !== 2 || str_starts_with($parts[0], '#')) {
                continue;
            }
            $name = trim($parts[1]);
            $type = null;
            if (str_starts_with($name, '[') && str_contains($name, ']')) {
                [$kind, $name] = explode(']', substr($name, 1), 2);
                $kind = mb_strtolower(trim($kind));
                $name = trim($name);
                $type = DeviceType::tryFrom($kind) ?? throw new CliError("unknown device type [$kind] in $path; use one of: "
                    . implode(', ', array_column(DeviceType::cases(), 'value')) . "\n", 1);
            }
            $byAddress[str_replace('-', ':', mb_strtolower($parts[0]))] = new CustomName(Text::orNull($name), $type);
        }
        return new self($byAddress);
    }

    public function lookup(Device $device): CustomName
    {
        foreach ([$device->mac, $device->ip] as $address) {
            if ($address !== null && isset($this->byAddress[mb_strtolower($address)])) {
                return $this->byAddress[mb_strtolower($address)];
            }
        }
        return new CustomName();
    }
}
