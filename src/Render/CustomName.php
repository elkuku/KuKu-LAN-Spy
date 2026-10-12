<?php

declare(strict_types=1);

namespace LanSpy\Render;

/** A line from device-names.txt: a name, a type in brackets, or both. */
final class CustomName
{
    public function __construct(public readonly ?string $name = null, public readonly ?DeviceType $type = null) {}
}
