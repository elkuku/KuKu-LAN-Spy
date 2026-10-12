#!/usr/bin/env php
<?php
/**
 * Render a lan-spy report or merged inventory as a standalone HTML page.
 *
 * Usage: ./render-html.php <report.json> [--refresh SECONDS] [--names FILE] > lan-devices.html
 *
 * Accepts the output of lan-spy.sh (single scan) or merge-reports.php (merged).
 * Custom device names are read from device-names.txt next to this script, if it
 * exists (see device-names.example.txt).
 */

declare(strict_types=1);

const DEFAULT_NAMES = __DIR__ . '/device-names.txt';

const CSS = <<<'CSS'
:root {
  --bg: #f7f7f5; --card: #ffffff; --text: #1f2328; --muted: #6b7280; --border: #e5e7eb;
  --chip: #eef2ff; --chip-text: #3730a3; --hint: #ecfdf5; --hint-text: #065f46;
  --up: #16a34a; --down: #9ca3af; --warn: #b45309; --name: #1d4ed8; --unnamed: #eab308;
}
@media (prefers-color-scheme: dark) {
  :root {
    --bg: #111317; --card: #1a1d23; --text: #e6e8eb; --muted: #9aa1ab; --border: #2b3038;
    --chip: #262b45; --chip-text: #c7d2fe; --hint: #10302a; --hint-text: #a7f3d0;
    --up: #22c55e; --down: #4b5563; --warn: #f59e0b; --name: #93c5fd; --unnamed: #facc15;
  }
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--text);
  font: 14px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; }
main { max-width: 1200px; margin: 0 auto; padding: 24px 16px 48px; }
h1 { font-size: 22px; margin: 0 0 4px; }
.sub { color: var(--muted); margin-bottom: 20px; }
.stats { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
.stat { background: var(--card); border: 1px solid var(--border); border-radius: 10px;
  padding: 10px 16px; min-width: 120px; }
.stat b { display: block; font-size: 22px; }
.stat span { color: var(--muted); font-size: 12px; }
.wrap { overflow-x: auto; background: var(--card); border: 1px solid var(--border); border-radius: 10px; }
table { border-collapse: collapse; width: 100%; min-width: 1000px; table-layout: fixed; }
th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border); vertical-align: top; }
th { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); white-space: nowrap; }
tr:last-child td { border-bottom: 0; }
tr.offline td { opacity: .55; }
.mono { font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size: 13px; }
.muted { color: var(--muted); font-size: 12px; }
.dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 6px; background: var(--down); }
.dot.up { background: var(--up); }
.chip { display: inline-block; background: var(--chip); color: var(--chip-text); border-radius: 6px;
  padding: 1px 7px; margin: 0 4px 4px 0; font-size: 12px; white-space: nowrap; }
.chip.hint { background: var(--hint); color: var(--hint-text); }
.chip.filtered { background: transparent; border: 1px dashed var(--border); color: var(--muted); }
.badge { font-size: 11px; color: var(--warn); }
.name { display: block; font-size: 16px; font-weight: 600; color: var(--name); }
tr.named td:first-child { box-shadow: inset 3px 0 0 var(--name); }
tr.unnamed td:first-child { box-shadow: inset 3px 0 0 var(--unnamed); }
.device { display: flex; gap: 10px; align-items: flex-start; }
.device > div { min-width: 0; }
.icon { flex: none; width: 26px; height: 26px; margin-top: 1px; color: var(--muted); }
.icon svg { width: 100%; height: 100%; fill: none; stroke: currentColor; stroke-width: 1.7;
  stroke-linecap: round; stroke-linejoin: round; }
tr.named .icon { color: var(--name); }
.toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 8px; }
.toolbar label { color: var(--muted); font-size: 12px; }
.toolbar button, .toolbar select { font: inherit; font-size: 12px; color: var(--text); background: var(--card);
  border: 1px solid var(--border); border-radius: 6px; padding: 4px 10px; cursor: pointer; }
tbody tr { cursor: pointer; }
tbody tr:hover td { background: color-mix(in srgb, var(--border) 35%, transparent); }
tbody tr:focus-visible { outline: 2px solid var(--name); outline-offset: -2px; }
.chev { display: inline-block; width: 12px; color: var(--muted); transition: transform .15s; }
tr.open .chev { transform: rotate(90deg); }
tr:not(.open) td { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; vertical-align: middle;
  padding-top: 7px; padding-bottom: 7px; }
tr:not(.open) .device { align-items: center; }
tr:not(.open) .device > div, tr:not(.open) .name { overflow: hidden; text-overflow: ellipsis; }
tr:not(.open) .name { font-size: 14px; }
tr:not(.open) .icon { width: 20px; height: 20px; margin-top: 0; }
tr:not(.open) .chip { margin-bottom: 0; }
tr:not(.open) .more, tr.open .less { display: none; }
CSS;

const SCRIPT = <<<'JS'
const KEY = "lan-spy-open";
const rows = [...document.querySelectorAll("tbody tr")];
let open;
try { open = new Set(JSON.parse(sessionStorage.getItem(KEY) || "[]")); } catch (e) { open = new Set(); }
const save = () => { try { sessionStorage.setItem(KEY, JSON.stringify([...open])); } catch (e) {} };
const toggleAll = document.getElementById("toggle-all");
const typeFilter = document.getElementById("type-filter");
const shown = () => rows.filter(r => !r.hidden);
function set(tr, on) {
  tr.classList.toggle("open", on);
  tr.setAttribute("aria-expanded", on);
  on ? open.add(tr.dataset.key) : open.delete(tr.dataset.key);
}
function sync() {
  toggleAll.textContent = shown().every(r => r.classList.contains("open")) ? "Collapse all" : "Expand all";
  save();
}
rows.forEach(tr => {
  set(tr, open.has(tr.dataset.key));
  tr.addEventListener("click", () => {
    if (getSelection().toString()) return;  // let people select and copy text
    set(tr, !tr.classList.contains("open")); sync();
  });
  tr.addEventListener("keydown", e => {
    if (e.key === "Enter" || e.key === " ") { e.preventDefault(); tr.click(); }
  });
});
toggleAll.addEventListener("click", () => {
  const on = toggleAll.textContent === "Expand all";
  shown().forEach(tr => set(tr, on)); sync();
});
function applyFilter() {
  rows.forEach(tr => { tr.hidden = typeFilter.value !== "" && tr.dataset.type !== typeFilter.value; });
  try { sessionStorage.setItem("lan-spy-type", typeFilter.value); } catch (e) {}
  sync();
}
try {
  const saved = sessionStorage.getItem("lan-spy-type");
  if (saved && [...typeFilter.options].some(o => o.value === saved)) typeFilter.value = saved;
} catch (e) {}
typeFilter.addEventListener("change", applyFilter);
applyFilter();
JS;

// Inline SVG bodies (24x24 viewBox, stroked) and a label per device type.
const ICONS = [
    'router' => ['Router', '<rect x="2" y="14" width="20" height="7" rx="2"/><path d="M6 17.5h.01M10 17.5h.01M15 14V9"/>'
                           . '<path d="M11.5 6.5a5 5 0 0 1 7 0M9 4a8.5 8.5 0 0 1 12 0"/>'],
    'wifi' => ['Access point / extender', '<path d="M2 9a14 14 0 0 1 20 0M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0M12 20h.01"/>'],
    'computer' => ['Computer', '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>'],
    'laptop' => ['Laptop', '<rect x="4" y="4" width="16" height="11" rx="1.5"/><path d="M2 19h20"/>'],
    'server' => ['Server / NAS', '<rect x="2" y="3" width="20" height="8" rx="2"/>'
                                 . '<rect x="2" y="13" width="20" height="8" rx="2"/><path d="M6 7h.01M6 17h.01"/>'],
    'phone' => ['Phone', '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>'],
    'tablet' => ['Tablet', '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M11 18h2"/>'],
    'tv' => ['TV / streaming', '<rect x="2" y="7" width="20" height="13" rx="2"/><path d="m7 2 5 5 5-5"/>'],
    'camera' => ['Camera', '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3z"/>'
                           . '<circle cx="12" cy="13" r="3"/>'],
    'speaker' => ['Speaker', '<rect x="5" y="2" width="14" height="20" rx="2"/>'
                             . '<circle cx="12" cy="14" r="4"/><path d="M12 6h.01"/>'],
    'printer' => ['Printer', '<path d="M6 9V2h12v7"/><rect x="2" y="9" width="20" height="9" rx="2"/>'
                             . '<rect x="6" y="14" width="12" height="8"/>'],
    'bulb' => ['Light', '<path d="M9 18h6M10 22h4"/>'
                        . '<path d="M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.2 1 2V18h6v-1.3c0-.8.4-1.5 1-2A7 7 0 0 0 12 2z"/>'],
    'plug' => ['Smart plug / switch', '<path d="M9 2v6M15 2v6M12 18v4M6 8h12v4a6 6 0 0 1-12 0z"/>'],
    'unknown' => ['Unknown device', '<circle cx="12" cy="12" r="10"/>'
                                    . '<path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M12 17h.01"/>'],
];

// Words in a custom name that give away the device type, checked in this order.
const NAME_KEYWORDS = [
    ['camera', ['cam', 'camara', 'camera', 'cámara', 'doorbell', 'timbre', 'webcam']],
    ['bulb', ['bulb', 'foco', 'lamp', 'lampara', 'light', 'luz', 'lámpara']],
    ['phone', ['celular', 'galaxy', 'handy', 'iphone', 'movil', 'móvil', 'phone', 'pixel', 'telefono', 'teléfono']],
    ['tablet', ['ipad', 'tablet']],
    ['tv', ['chromecast', 'firestick', 'roku', 'television', 'televisión', 'tv']],
    ['laptop', ['laptop', 'macbook', 'notebook', 'thinkpad']],
    ['computer', ['computadora', 'computer', 'desktop', 'imac', 'pc']],
    ['server', ['nas', 'pi', 'raspberry', 'server', 'servidor']],
    ['printer', ['drucker', 'impresora', 'printer']],
    ['speaker', ['alexa', 'echo', 'parlante', 'sonos', 'speaker']],
    ['router', ['gateway', 'modem', 'módem', 'router']],
    ['wifi', ['ap', 'extender', 'mesh', 'repeater', 'repetidor']],
    ['plug', ['enchufe', 'outlet', 'plug', 'socket']],
];

function esc(?string $s): string
{
    return strtr((string) $s, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#x27;']);
}

/** Python-style truthiness for optional strings: null and '' are missing, '0' is a value. */
function present(?string $s): bool
{
    return $s !== null && $s !== '';
}

/** Use the system timezone (PHP defaults to UTC) so "Generated" shows local time. */
function use_local_timezone(): void
{
    $tz = getenv('TZ') ?: (preg_match('#zoneinfo/(.+)$#', (string) @readlink('/etc/localtime'), $m) ? $m[1] : '');
    if (in_array($tz, timezone_identifiers_list(), true)) {
        date_default_timezone_set($tz);
    }
}

function ip_cmp(string $a, string $b): int
{
    $a = inet_pton($a);
    $b = inet_pton($b);
    return [strlen($a), $a] <=> [strlen($b), $b];
}

function fmt_time(?string $iso): string
{
    if (!present($iso)) {
        return '';
    }
    try {
        return (new DateTimeImmutable($iso))->format('Y-m-d H:i');
    } catch (Exception) {
        return $iso;
    }
}

function has_open_ports(array $d): bool
{
    foreach ($d['ports'] ?? [] as $p) {
        if (($p['state'] ?? null) === 'open') {
            return true;
        }
    }
    return false;
}

function ports_html(array $d): string
{
    $ports = $d['ports'] ?? null;
    if ($ports === null) {
        return '<span class="muted">not scanned</span>';
    }
    $chips = [];
    foreach ($ports as $p) {
        $label = $p['port'] . '/' . (($p['service'] ?? '') ?: '?');
        $title = implode(' — ', array_filter([$p['version'] ?? null, $p['hint'] ?? null], 'present'));
        $cls = 'chip';
        if (($p['state'] ?? null) !== 'open') {
            $cls .= ' filtered more';
            $label .= ' (' . ($p['state'] ?? 'None') . ')';
        } elseif (present($p['hint'] ?? null)) {
            $cls .= ' hint';
            $label = "{$p['port']} {$p['hint']}";
        }
        $chips[] = '<span class="' . $cls . '" title="' . esc($title) . '">' . esc($label) . '</span>';
    }
    $rest = $d['ports_not_shown'] ?? null;
    if (present($rest)) {
        $chips[] = '<div class="muted more">' . esc($rest) . '</div>';
    }
    if (!has_open_ports($d)) {
        array_unshift($chips, '<span class="muted less">none open</span>');
    }
    return implode('', $chips);
}

function os_html(array $d): string
{
    $os = $d['os'] ?? [];
    $name = ($os['best_guess'] ?? '') ?: ($os['details'] ?? '');
    if (!present($name)) {
        return '<span class="muted">unknown</span>';
    }
    $out = esc($name);
    if (!empty($os['accuracy'])) {
        $out .= ' <span class="muted">' . $os['accuracy'] . '%</span>';
    }
    if (($os['source'] ?? null) === 'local') {
        $out .= '<div class="muted more">read locally</div>';
    } elseif (!has_open_ports($d)) {
        $out .= '<div class="badge more">unreliable: no open ports</div>';
    }
    return $out;
}

/**
 * Read custom device names: one '<mac-or-ip> [type] <name>' per line, '#' lines are comments.
 *
 * @return array<string, array{?string, ?string}> address => [name, type]
 */
function load_names(string $path): array
{
    $names = [];
    foreach (preg_split('/\r\n|\r|\n/', file_get_contents($path)) as $line) {
        $parts = preg_split('/\s+/u', ltrim($line), 2, PREG_SPLIT_NO_EMPTY);
        if (count($parts) !== 2 || str_starts_with($parts[0], '#')) {
            continue;
        }
        $name = trim($parts[1]);
        $kind = null;
        if (str_starts_with($name, '[') && str_contains($name, ']')) {
            [$kind, $name] = explode(']', substr($name, 1), 2);
            $kind = mb_strtolower(trim($kind));
            $name = trim($name);
            if (!isset(ICONS[$kind])) {
                fwrite(STDERR, "unknown device type [$kind] in $path; use one of: " . implode(', ', array_keys(ICONS)) . "\n");
                exit(1);
            }
        }
        $names[str_replace('-', ':', mb_strtolower($parts[0]))] = [present($name) ? $name : null, $kind];
    }
    return $names;
}

function custom_name(array $d, array $names): array
{
    foreach ([$d['mac'] ?? null, $d['ip']] as $key) {
        if (present($key) && isset($names[mb_strtolower($key)])) {
            return $names[mb_strtolower($key)];
        }
    }
    return [null, null];
}

/** Guess an icon key from the scan data and the custom name, strongest signal first. */
function device_type(array $d, ?string $name): string
{
    $role = $d['role'] ?? null;
    if ($role === 'gateway') {
        return 'router';
    }
    if ($role === 'this machine') {
        return 'computer';
    }
    $openPorts = [];
    foreach ($d['ports'] ?? [] as $p) {
        if (($p['state'] ?? null) === 'open') {
            $openPorts[$p['port']] = $p;
        }
    }
    $isOpen = fn(int ...$ports) => (bool) array_intersect_key($openPorts, array_flip($ports));
    // avahi-browse may report "_ipp._tcp" or its readable name "Internet Printer", so match loosely.
    $mdns = mb_strtolower(implode(' ', array_map(fn($m) => $m['service'] ?? '', $d['mdns'] ?? [])));
    $announces = function (string ...$words) use ($mdns): bool {
        foreach ($words as $w) {
            if (str_contains($mdns, $w)) {
                return true;
            }
        }
        return false;
    };
    $rtsp = array_filter($openPorts, fn($p) => str_contains($p['service'] ?? '', 'rtsp'));
    if ($isOpen(554) || $rtsp) {
        return 'camera';
    }
    if ($isOpen(8009) || $announces('googlecast', 'google cast', 'airplay', 'androidtvremote')) {
        return 'tv';
    }
    if ($isOpen(631, 9100) || $announces('_ipp', 'printer', 'pdl-datastream')) {
        return 'printer';
    }
    if (present($name)) {
        $words = preg_split('/\s+/u', mb_strtolower(str_replace(['-', '_'], ' ', $name)), -1, PREG_SPLIT_NO_EMPTY);
        foreach (NAME_KEYWORDS as [$kind, $keywords]) {
            if (array_intersect($words, $keywords)) {
                return $kind;
            }
        }
    }
    if ($announces('raop', 'airtunes', 'spotify', 'sonos')) {
        return 'speaker';
    }
    $vendor = mb_strtolower($d['vendor'] ?? '');
    $iotVendor = array_filter(['tuya', 'espressif', 'shelly', 'sonoff'], fn($v) => str_contains($vendor, $v));
    if ($isOpen(6668, 9999) || $iotVendor) {
        return 'plug';
    }
    if ($isOpen(53)) {
        return 'wifi';
    }
    $osName = mb_strtolower(($d['os']['best_guess'] ?? '') ?: '');
    if (str_contains($osName, 'android') || in_array('ios', preg_split('/\s+/u', $osName), true)) {
        return 'phone';
    }
    if (str_contains($osName, 'windows') || str_contains($osName, 'macos') || str_contains($osName, 'mac os')) {
        return 'computer';
    }
    if (!empty($d['mac_locally_administered'])) {
        return 'phone';
    }
    return 'unknown';
}

function icon_html(string $kind): string
{
    [$label, $body] = ICONS[$kind];
    return '<span class="icon" title="' . esc($label) . '">'
        . '<svg viewBox="0 0 24 24" role="img" aria-label="' . esc($label) . '">' . $body . '</svg></span>';
}

function device_row(array $d, string $kind, bool $online, bool $merged, int $totalReports, array $customNames): string
{
    $names = [$d['hostname'] ?? null, $d['netbios'] ?? null];
    foreach ($d['mdns'] ?? [] as $m) {
        $names[] = $m['hostname'] ?? null;
    }
    $names = array_values(array_unique(array_filter($names, 'present')));
    $who = esc(($d['vendor'] ?? '') ?: (!empty($d['mac_locally_administered']) ? 'Private MAC' : 'Unknown vendor'));
    $extra = array_map('esc', $names);
    [$custom] = custom_name($d, $customNames);
    $icon = icon_html($kind);
    if ($custom !== null) {
        array_unshift($extra, $who);
        $who = '<span class="name">' . esc($custom) . '</span>';
    }
    if (present($d['role'] ?? null)) {
        array_unshift($extra, '<b>' . esc($d['role']) . '</b>');
    }
    $services = array_unique(array_filter(array_map(fn($m) => $m['service'] ?? null, $d['mdns'] ?? []), 'present'));
    sort($services, SORT_STRING);
    if ($services) {
        $extra[] = 'mDNS: ' . esc(implode(', ', $services));
    }

    $mac = esc(($d['mac'] ?? '') ?: '—');
    if (!empty($d['mac_locally_administered'])) {
        $mac .= '<div class="muted more">randomized</div>';
    }

    // Blue marker for named devices, yellow for online ones that still need a name.
    $rowClass = implode(' ', array_filter([$custom !== null ? 'named' : ($online ? 'unnamed' : ''),
        $online ? '' : 'offline']));

    $latency = isset($d['latency_ms']) ? sprintf('%g ms', $d['latency_ms']) : '—';
    if ($merged) {
        $seen = ($d['times_seen'] ?? 0) . "/$totalReports"
            . '<div class="muted more">last ' . esc(fmt_time($d['last_seen'] ?? null)) . '</div>';
    } else {
        $seen = esc($d['deep_scan'] ?? '');
    }

    $key = esc(($d['mac'] ?? '') ?: $d['ip']);
    $up = $online ? ' up' : '';
    $ip = esc($d['ip']);
    $extraHtml = implode('<br>', $extra);
    $ports = ports_html($d);
    $os = os_html($d);
    return <<<HTML
        <tr class="$rowClass" data-key="$key" data-type="$kind" tabindex="0" aria-expanded="false">
        <td class="mono"><span class="chev">›</span><span class="dot$up"></span>$ip</td>
        <td><div class="device">$icon<div>$who<div class="muted more">$extraHtml</div></div></div></td>
        <td class="mono">$mac</td>
        <td>$ports</td>
        <td>$os</td>
        <td class="mono">$latency</td>
        <td>$seen</td>
        </tr>
        HTML;
}

function render(array $data, int $refresh, array $customNames): string
{
    $merged = array_key_exists('merged_from', $data);
    if ($merged) {
        $reports = $data['merged_from'];
        $dates = array_column($reports, 'date');
        usort($dates, 'strcmp');
        $latest = end($dates);
        $total = count($reports);
        [$network, $gateway] = [$data['network'] ?? null, $data['gateway'] ?? null];
    } else {
        $latest = $data['scan']['date'];
        $total = 1;
        [$network, $gateway] = [$data['scan']['network'] ?? null, $data['scan']['gateway'] ?? null];
    }

    $devices = $data['devices'];
    usort($devices, fn($a, $b) => ip_cmp($a['ip'], $b['ip']));
    $isOnline = fn($d) => !$merged || ($d['last_seen'] ?? null) === $latest;
    $online = count(array_filter($devices, $isOnline));
    $openPorts = 0;
    foreach ($devices as $d) {
        foreach ($d['ports'] ?? [] as $p) {
            $openPorts += ($p['state'] ?? null) === 'open' ? 1 : 0;
        }
    }

    $kinds = [];
    foreach ($devices as $i => $d) {
        [$custom, $kind] = custom_name($d, $customNames);
        $kinds[$i] = $kind ?? device_type($d, $custom);
    }
    $counts = array_count_values($kinds);
    $typeOptions = '<option value="">All types (' . count($devices) . ')</option>';
    foreach (ICONS as $k => [$label]) {
        if (!empty($counts[$k])) {
            $typeOptions .= '<option value="' . $k . '">' . esc($label) . " ({$counts[$k]})</option>";
        }
    }

    $rows = [];
    foreach ($devices as $i => $d) {
        $rows[] = device_row($d, $kinds[$i], $isOnline($d), $merged, $total, $customNames);
    }
    $rows = implode("\n", $rows);
    $metaRefresh = $refresh ? '<meta http-equiv="refresh" content="' . $refresh . '">' : '';
    $stats = [
        [count($devices), $merged ? 'devices known' : 'devices'],
        [$online, 'in latest scan'],
        [$openPorts, 'open ports'],
    ];
    if ($merged) {
        $stats[] = [$total, 'scans merged'];
    }
    $statsHtml = implode('', array_map(fn($s) => '<div class="stat"><b>' . $s[0] . '</b><span>' . esc($s[1]) . '</span></div>', $stats));

    $css = "\n" . CSS . "\n";
    $script = "\n" . SCRIPT . "\n";
    $sub = esc($network ?? '') . ' · gateway ' . esc($gateway ?? '?') . ' · latest scan ' . esc(fmt_time($latest));
    $seenHeader = $merged ? 'Seen' : 'Port scan';
    $generated = esc(date('Y-m-d H:i:s'));

    return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        $metaRefresh
        <title>LAN Devices</title>
        <style>$css</style>
        </head>
        <body>
        <main>
        <h1>LAN Devices</h1>
        <div class="sub">$sub</div>
        <div class="stats">$statsHtml</div>
        <div class="toolbar">
        <label>Type <select id="type-filter">$typeOptions</select></label>
        <button type="button" id="toggle-all">Expand all</button>
        </div>
        <div class="wrap">
        <table>
        <colgroup><col style="width:165px"><col style="width:20%"><col style="width:168px"><col>
        <col style="width:17%"><col style="width:95px"><col style="width:90px"></colgroup>
        <thead><tr><th>IP</th><th>Device</th><th>MAC</th><th>Ports</th><th>OS guess</th><th>Latency</th><th>$seenHeader</th></tr></thead>
        <tbody>
        $rows
        </tbody>
        </table>
        </div>
        <p class="muted">Generated $generated by render-html.php.
        Click a row for details. Greyed-out rows were not seen in the latest scan; a yellow edge marks online
        devices without a custom name.</p>
        </main>
        <script>$script</script>
        </body>
        </html>

        HTML;
}

function usage(): string
{
    return "usage: render-html.php <report.json> [--refresh SECONDS] [--names FILE]\n";
}

function main(array $argv): void
{
    use_local_timezone();
    $report = null;
    $refresh = 0;
    $namesPath = DEFAULT_NAMES;
    $args = array_slice($argv, 1);
    while ($args) {
        $arg = array_shift($args);
        if ($arg === '-h' || $arg === '--help') {
            echo usage(), "\n",
                "  --refresh SECONDS  auto-reload the page every N seconds\n",
                "  --names FILE       custom device names file (default: device-names.txt next to this script, if present)\n";
            exit(0);
        }
        if (preg_match('/^--(refresh|names)(?:=(.*))?$/s', $arg, $m)) {
            $value = $m[2] ?? array_shift($args);
            if ($value === null) {
                fwrite(STDERR, usage() . "render-html.php: error: --{$m[1]} needs a value\n");
                exit(2);
            }
            if ($m[1] === 'refresh') {
                if (!preg_match('/^-?\d+$/', trim($value))) {
                    fwrite(STDERR, usage() . "render-html.php: error: --refresh: invalid number: '$value'\n");
                    exit(2);
                }
                $refresh = (int) $value;
            } else {
                $namesPath = $value;
            }
        } elseif ($report === null && ($arg === '-' || !str_starts_with($arg, '-'))) {
            $report = $arg;
        } else {
            fwrite(STDERR, usage() . "render-html.php: error: unrecognized argument: $arg\n");
            exit(2);
        }
    }
    if ($report === null) {
        fwrite(STDERR, usage() . "render-html.php: error: the report argument is required\n");
        exit(2);
    }
    $raw = @file_get_contents($report);
    if ($raw === false) {
        fwrite(STDERR, "cannot read $report\n");
        exit(1);
    }
    $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    if ($namesPath !== DEFAULT_NAMES && !file_exists($namesPath)) {
        fwrite(STDERR, "names file not found: $namesPath\n");
        exit(1);
    }
    $customNames = file_exists($namesPath) ? load_names($namesPath) : [];
    echo render($data, $refresh, $customNames);
}

main($argv);
