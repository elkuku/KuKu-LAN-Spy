#!/usr/bin/env php
<?php
/**
 * Merge lan-spy JSON reports into one device inventory.
 *
 * Usage: ./merge-reports.php [report.json ...] > lan-devices-merged.json
 * With no arguments, merges every lan-report-*.json in the current directory.
 *
 * Devices are matched by MAC address (by IP when there is no MAC). For each
 * device the newest report with a value wins; port and OS data come together
 * from the newest report in which the device was actually port-scanned.
 */

declare(strict_types=1);

const SIMPLE_FIELDS = ['vendor', 'mac_locally_administered', 'hostname', 'netbios', 'role'];
const SCAN_FIELDS = ['ports', 'ports_not_shown', 'os', 'network_distance', 'latency_ms'];

function ip_cmp(string $a, string $b): int
{
    $a = inet_pton($a);
    $b = inet_pton($b);
    return [strlen($a), $a] <=> [strlen($b), $b];
}

function json_out(mixed $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    // Two-space indent, like the reports have always had.
    echo preg_replace_callback('/^(?: {4})+/m', fn($m) => str_repeat(' ', strlen($m[0]) / 2), $json), "\n";
}

/** @return list<array{string, string, array}> [date, path, report], oldest first so newer values overwrite */
function load(array $paths): array
{
    $reports = [];
    foreach ($paths as $path) {
        try {
            $raw = @file_get_contents($path);
            if ($raw === false) {
                throw new RuntimeException(error_get_last()['message'] ?? 'cannot read file');
            }
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException|RuntimeException $e) {
            fwrite(STDERR, "skipping $path: {$e->getMessage()}\n");
            continue;
        }
        $reports[] = [$data['scan']['date'], (string) $path, $data];
    }
    usort($reports, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    return $reports;
}

function merge(array $reports): array
{
    $devices = [];
    foreach ($reports as [$date, , $data]) {
        foreach ($data['devices'] ?? [] as $d) {
            $key = 'k' . (($d['mac'] ?? '') ?: $d['ip']);
            $m = &$devices[$key];
            $m ??= [
                'ip' => $d['ip'], 'mac' => $d['mac'] ?? null, 'ips_seen' => [],
                ...array_fill_keys(SIMPLE_FIELDS, null),
                'first_seen' => $date, 'last_seen' => $date, 'times_seen' => 0, 'seen_by' => [],
                'mdns' => [], 'last_port_scan' => null,
                ...array_fill_keys(SCAN_FIELDS, null),
            ];
            $m['ip'] = $d['ip'];
            if (!in_array($d['ip'], $m['ips_seen'], true)) {
                $m['ips_seen'][] = $d['ip'];
            }
            $m['last_seen'] = $date;
            $m['times_seen']++;
            foreach ($d['seen_by'] ?? [] as $src) {
                if (!in_array($src, $m['seen_by'], true)) {
                    $m['seen_by'][] = $src;
                }
            }
            foreach (SIMPLE_FIELDS as $f) {
                if (isset($d[$f])) {
                    $m[$f] = $d[$f];
                }
            }
            foreach ($d['mdns'] ?? [] as $entry) {
                if (!in_array($entry, $m['mdns'], true)) {
                    $m['mdns'][] = $entry;
                }
            }
            // "local" is this machine, whose ports are read from the kernel instead.
            if (in_array($d['deep_scan'] ?? null, ['ok', 'local'], true)) {
                $m['last_port_scan'] = $date;
                foreach (SCAN_FIELDS as $f) {
                    $m[$f] = $d[$f] ?? null;
                }
            }
            unset($m);
        }
    }
    $devices = array_values($devices);
    usort($devices, fn($a, $b) => ip_cmp($a['ip'], $b['ip']));
    return $devices;
}

$paths = array_slice($argv, 1) ?: glob('lan-report-*.json');
$reports = load($paths);
if (!$reports) {
    fwrite(STDERR, "no reports to merge\n");
    exit(1);
}
$newest = end($reports)[2]['scan'];
json_out([
    'merged_from' => array_map(fn($r) => ['file' => $r[1], 'date' => $r[0], 'devices' => count($r[2]['devices'] ?? [])],
        $reports),
    'network' => $newest['network'] ?? null,
    'gateway' => $newest['gateway'] ?? null,
    'local_ip' => $newest['local_ip'] ?? null,
    'devices' => merge($reports),
]);
