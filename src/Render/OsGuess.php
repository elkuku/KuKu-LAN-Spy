<?php

declare(strict_types=1);

namespace LanSpy\Render;

use LanSpy\Util\Text;

/** nmap's OS guess for a device, or the OS read locally for this machine. */
final class OsGuess
{
    public function __construct(
        public readonly ?string $bestGuess,
        public readonly ?string $details,
        public readonly int|float|null $accuracy,
        public readonly ?string $source,
    ) {}

    /** @param ?array<string, mixed> $os */
    public static function fromArray(?array $os): self
    {
        return new self(
            Text::orNull($os['best_guess'] ?? null),
            Text::orNull($os['details'] ?? null),
            $os['accuracy'] ?? null,
            Text::orNull($os['source'] ?? null)
        );
    }
}
