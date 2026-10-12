<?php

declare(strict_types=1);

namespace LanSpy\Build;

use JsonSerializable;
use LanSpy\Util\Text;
use SimpleXMLElement;

final class Port implements JsonSerializable
{
    // nmap names these after unrelated services; on a home LAN they usually mean this.
    private const IOT_HINTS = [
        6668 => 'Tuya smart device local API',
        8009 => 'Google Cast',
        9999 => 'TP-Link Kasa smart device API',
    ];

    public function __construct(
        public readonly int $port,
        public readonly ?string $proto,
        public readonly ?string $state,
        public readonly ?string $service,
        public readonly ?string $version,
        public readonly ?string $hint = null,
    ) {}

    public static function fromNmap(SimpleXMLElement $element): self
    {
        $port = (int) NmapXml::attr($element, 'portid');
        $state = NmapXml::attr($element->state[0] ?? null, 'state');
        $svc = $element->service[0] ?? null;
        $version = null;
        if ($svc !== null) {
            $extraInfo = NmapXml::attr($svc, 'extrainfo');
            $extraInfo = Text::present($extraInfo) ? "($extraInfo)" : null;
            $version = implode(' ', array_filter(
                [NmapXml::attr($svc, 'product'), NmapXml::attr($svc, 'version'), $extraInfo],
                Text::present(...),
            ));
        }
        return new self(
            $port,
            NmapXml::attr($element, 'protocol'),
            $state,
            NmapXml::attr($svc, 'name'),
            Text::present($version) ? $version : null,
            $state === 'open' ? self::hintFor($port) : null
        );
    }

    /** @param list<string> $procs the processes listening on it */
    public static function local(int $port, array $procs): self
    {
        return new self(
            $port,
            'tcp',
            'open',
            getservbyport($port, 'tcp') ?: null,
            implode(', ', $procs) ?: null,
            self::hintFor($port)
        );
    }

    public static function hintFor(int $port): ?string
    {
        return self::IOT_HINTS[$port] ?? null;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $out = ['port' => $this->port, 'proto' => $this->proto, 'state' => $this->state,
            'service' => $this->service, 'version' => $this->version];
        if ($this->hint !== null) {
            $out['hint'] = $this->hint;
        }
        return $out;
    }
}
