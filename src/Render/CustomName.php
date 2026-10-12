<?php

declare(strict_types=1);

namespace LanSpy\Render;

/** A line from device-names.txt: a name, a type in brackets, or both. */
final readonly class CustomName
{
    public function __construct(public ?string $name = null, public ?DeviceType $type = null) {}
}
