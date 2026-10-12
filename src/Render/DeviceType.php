<?php

declare(strict_types=1);

namespace LanSpy\Render;

use LanSpy\Util\Text;

/** A device type: its label and icon on the page, and the words in a custom name that give it away. */
enum DeviceType: string
{
    case Router = 'router';
    case Wifi = 'wifi';
    case Computer = 'computer';
    case Laptop = 'laptop';
    case Server = 'server';
    case Phone = 'phone';
    case Tablet = 'tablet';
    case Tv = 'tv';
    case Camera = 'camera';
    case Speaker = 'speaker';
    case Printer = 'printer';
    case Bulb = 'bulb';
    case Plug = 'plug';
    case Unknown = 'unknown';

    // The order custom names are checked for keywords in; the first match wins.
    private const NAME_ORDER = [
        self::Camera, self::Bulb, self::Phone, self::Tablet, self::Tv, self::Laptop, self::Computer,
        self::Server, self::Printer, self::Speaker, self::Router, self::Wifi, self::Plug,
    ];

    public function label(): string
    {
        return match ($this) {
            self::Router => 'Router',
            self::Wifi => 'Access point / extender',
            self::Computer => 'Computer',
            self::Laptop => 'Laptop',
            self::Server => 'Server / NAS',
            self::Phone => 'Phone',
            self::Tablet => 'Tablet',
            self::Tv => 'TV / streaming',
            self::Camera => 'Camera',
            self::Speaker => 'Speaker',
            self::Printer => 'Printer',
            self::Bulb => 'Light',
            self::Plug => 'Smart plug / switch',
            self::Unknown => 'Unknown device',
        };
    }

    /** Inline SVG body for a 24x24 viewBox, drawn with strokes. */
    public function icon(): string
    {
        return match ($this) {
            self::Router => '<rect x="2" y="14" width="20" height="7" rx="2"/><path d="M6 17.5h.01M10 17.5h.01M15 14V9"/>'
                . '<path d="M11.5 6.5a5 5 0 0 1 7 0M9 4a8.5 8.5 0 0 1 12 0"/>',
            self::Wifi => '<path d="M2 9a14 14 0 0 1 20 0M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0M12 20h.01"/>',
            self::Computer => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
            self::Laptop => '<rect x="4" y="4" width="16" height="11" rx="1.5"/><path d="M2 19h20"/>',
            self::Server => '<rect x="2" y="3" width="20" height="8" rx="2"/>'
                . '<rect x="2" y="13" width="20" height="8" rx="2"/><path d="M6 7h.01M6 17h.01"/>',
            self::Phone => '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>',
            self::Tablet => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M11 18h2"/>',
            self::Tv => '<rect x="2" y="7" width="20" height="13" rx="2"/><path d="m7 2 5 5 5-5"/>',
            self::Camera => '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3z"/>'
                . '<circle cx="12" cy="13" r="3"/>',
            self::Speaker => '<rect x="5" y="2" width="14" height="20" rx="2"/>'
                . '<circle cx="12" cy="14" r="4"/><path d="M12 6h.01"/>',
            self::Printer => '<path d="M6 9V2h12v7"/><rect x="2" y="9" width="20" height="9" rx="2"/>'
                . '<rect x="6" y="14" width="12" height="8"/>',
            self::Bulb => '<path d="M9 18h6M10 22h4"/>'
                . '<path d="M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.2 1 2V18h6v-1.3c0-.8.4-1.5 1-2A7 7 0 0 0 12 2z"/>',
            self::Plug => '<path d="M9 2v6M15 2v6M12 18v4M6 8h12v4a6 6 0 0 1-12 0z"/>',
            self::Unknown => '<circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M12 17h.01"/>',
        };
    }

    /** @return list<string> */
    public function keywords(): array
    {
        return match ($this) {
            self::Camera => ['cam', 'camara', 'camera', 'cámara', 'doorbell', 'timbre', 'webcam'],
            self::Bulb => ['bulb', 'foco', 'lamp', 'lampara', 'light', 'luz', 'lámpara'],
            self::Phone => ['celular', 'galaxy', 'handy', 'iphone', 'movil', 'móvil', 'phone', 'pixel', 'telefono', 'teléfono'],
            self::Tablet => ['ipad', 'tablet'],
            self::Tv => ['chromecast', 'firestick', 'roku', 'television', 'televisión', 'tv'],
            self::Laptop => ['laptop', 'macbook', 'notebook', 'thinkpad'],
            self::Computer => ['computadora', 'computer', 'desktop', 'imac', 'pc'],
            self::Server => ['nas', 'pi', 'raspberry', 'server', 'servidor'],
            self::Printer => ['drucker', 'impresora', 'printer'],
            self::Speaker => ['alexa', 'echo', 'parlante', 'sonos', 'speaker'],
            self::Router => ['gateway', 'modem', 'módem', 'router'],
            self::Wifi => ['ap', 'extender', 'mesh', 'repeater', 'repetidor'],
            self::Plug => ['enchufe', 'outlet', 'plug', 'socket'],
            self::Unknown => [],
        };
    }

    /** The type a custom name gives away, if any. */
    public static function fromName(string $name): ?self
    {
        $words = preg_split('/\s+/u', mb_strtolower(str_replace(['-', '_'], ' ', $name)), -1, PREG_SPLIT_NO_EMPTY);
        foreach (self::NAME_ORDER as $type) {
            if (array_intersect($words, $type->keywords())) {
                return $type;
            }
        }
        return null;
    }

    /** Guess the type from the scan data and the custom name, strongest signal first. */
    public static function guess(Device $device, ?string $name): self
    {
        if ($device->role === 'gateway') {
            return self::Router;
        }
        if ($device->role === 'this machine') {
            return self::Computer;
        }
        $openPorts = $device->openPorts();
        $isOpen = fn(int ...$numbers): bool => (bool) array_filter($openPorts, fn(Port $port) => in_array($port->port, $numbers, true));
        // avahi-browse may report "_ipp._tcp" or its readable name "Internet Printer", so match loosely.
        $mdns = mb_strtolower(implode(' ', array_map(fn(MdnsEntry $entry) => $entry->service ?? '', $device->mdns)));
        $rtsp = array_filter($openPorts, fn(Port $port) => str_contains($port->service ?? '', 'rtsp'));
        $osName = mb_strtolower($device->os->bestGuess ?? '');
        $fromName = $name !== null ? self::fromName($name) : null;

        return match (true) {
            $isOpen(554) || $rtsp => self::Camera,
            $isOpen(8009) || Text::containsAny($mdns, 'googlecast', 'google cast', 'airplay', 'androidtvremote') => self::Tv,
            $isOpen(631, 9100) || Text::containsAny($mdns, '_ipp', 'printer', 'pdl-datastream') => self::Printer,
            $fromName !== null => $fromName,
            Text::containsAny($mdns, 'raop', 'airtunes', 'spotify', 'sonos') => self::Speaker,
            $isOpen(6668, 9999) || Text::containsAny(mb_strtolower($device->vendor ?? ''), 'tuya', 'espressif', 'shelly', 'sonoff')
                => self::Plug,
            $isOpen(53) => self::Wifi,
            str_contains($osName, 'android') || in_array('ios', preg_split('/\s+/u', $osName), true) => self::Phone,
            Text::containsAny($osName, 'windows', 'macos', 'mac os') => self::Computer,
            $device->macRandomized => self::Phone,
            default => self::Unknown,
        };
    }
}
