<?php

declare(strict_types=1);

namespace LanSpy\Render;

use LanSpy\Util\Text;

/** A port of a device as the report lists it. */
final class Port
{
    public function __construct(
        public readonly int $port,
        public readonly ?string $state,
        public readonly ?string $service,
        public readonly ?string $version,
        public readonly ?string $hint,
    ) {
    }

    public static function fromArray(array $port): self
    {
        return new self((int) $port['port'], Text::orNull($port['state'] ?? null), Text::orNull($port['service'] ?? null),
            Text::orNull($port['version'] ?? null), Text::orNull($port['hint'] ?? null));
    }

    public function isOpen(): bool
    {
        return $this->state === 'open';
    }
}
