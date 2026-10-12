<?php

declare(strict_types=1);

namespace LanSpy\Merge;

use JsonException;
use RuntimeException;

/** One lan-spy report as read from disk. */
final class ReportFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $date,
        public readonly array $data,
    ) {
    }

    /** @throws RuntimeException|JsonException if the file cannot be read or parsed */
    public static function load(string $path): self
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException(error_get_last()['message'] ?? 'cannot read file');
        }
        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        return new self($path, $data['scan']['date'] ?? throw new RuntimeException('no scan date'), $data);
    }

    /** @return list<array> */
    public function devices(): array
    {
        return $this->data['devices'] ?? [];
    }
}
