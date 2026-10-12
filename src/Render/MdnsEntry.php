<?php

declare(strict_types=1);

namespace LanSpy\Render;

use LanSpy\Util\Text;

/** A service a device announces over mDNS. */
final class MdnsEntry
{
    public function __construct(public readonly ?string $hostname, public readonly ?string $service) {}

    /** @param array<string, mixed> $entry */
    public static function fromArray(array $entry): self
    {
        return new self(Text::orNull($entry['hostname'] ?? null), Text::orNull($entry['service'] ?? null));
    }
}
