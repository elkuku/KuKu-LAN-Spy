# kuku-lan-spy

Inventory the devices on your local network and write the results as JSON
and as an HTML page.

## Requirements

- `arp-scan`, `nmap`
- `avahi-browse` (avahi) for mDNS/Bonjour discovery
- `nmblookup` (smbclient) for NetBIOS names
- Python 3 (standard library only)

On Arch Linux: `sudo pacman -S arp-scan nmap avahi smbclient python`

## Scripts

### `lan-spy.sh` — run a scan

```sh
sudo ./lan-spy.sh [interface] [output-file]
```

Must run as root. Defaults to the interface of the default route (so Docker
bridges are ignored) and to `lan-report-<timestamp>.json`.

Steps:

1. **arp-scan** and **nmap host discovery** (`-sn`) find live hosts. The union
   of both is used, plus this machine.
2. **Reverse DNS** (`getent`) and **NetBIOS** (`nmblookup`) names per host.
3. **mDNS** — listens for 15 seconds with `avahi-browse`, keeping only records
   seen on the scanned interface.
4. **Port scan** — nmap service and OS detection (`-sV -O`) on nmap's top 100
   TCP ports plus 6668 (Tuya local API). `-Pn` is used, and hosts that do not
   answer are retried once, since sleeping IoT devices often miss the first
   attempt. This machine is skipped.
5. **This machine** cannot scan itself over the LAN, so its details are read
   locally instead: the interface MAC (`/sys/class/net`), the OS and kernel
   (`/etc/os-release`, `uname`) and the TCP ports it listens on for the LAN
   (`ss -ltnp`, with the owning process as the version).
6. **`build-report.py`** turns the collected data into the JSON report.

The raw nmap XML is kept in `lan-report-<timestamp>-raw/` for troubleshooting.
When run through `sudo`, the report and raw folder are handed back to the
calling user.

### `build-report.py` — build the JSON report

Called by `lan-spy.sh`; you normally don't run it directly.

```sh
./build-report.py <scan-dir> <interface> <cidr> <gateway> <hostname> > report.json
```

Reads the intermediate files from `<scan-dir>` (`arp.tsv`, `discovery.xml`,
`dns.tsv`, `netbios.tsv`, `mdns.txt`, `targets.txt`, `deep.xml`,
`deep-retry.xml`) and writes one entry per device:

| Field | Meaning |
|---|---|
| `ip`, `mac` | Addresses |
| `vendor` | From nmap, falling back to nmap's MAC prefix list |
| `mac_locally_administered` | `true` for randomized/private MACs (usually phones and laptops) |
| `hostname`, `netbios`, `mdns` | Names and services the device announces |
| `role` | `gateway` or `this machine` |
| `seen_by` | Which discovery tools found it (`arp-scan`, `nmap`) |
| `deep_scan` | `ok`, `no response (...)`, or `local` (this machine, read locally) |
| `ports` | Ports nmap reported, with service, version and an IoT `hint` for 6668, 8009 and 9999 |
| `ports_not_shown` | Summary of the remaining ports, e.g. `100 closed` |
| `os` | Best OS guess, top guesses with accuracy, device types, CPEs |
| `latency_ms`, `network_distance` | From the port scan |

OS guesses for hosts with no open ports are unreliable — nmap needs at least
one open and one closed port to fingerprint well.

### `merge-reports.py` — combine several reports

```sh
./merge-reports.py [report.json ...] > lan-devices-merged.json
```

With no arguments, merges every `lan-report-*.json` in the current directory.
Because sleepy devices answer some runs and not others, merging a few scans
gives a more complete picture than any single one.

- Devices are matched by MAC address (by IP when there is none). This
  machine has no MAC in older reports, so it is matched there by hostname.
- For names, vendor and role, the newest report with a value wins.
- Ports, OS, latency and distance are taken together from the newest report
  in which the device was actually port-scanned.
- Each device also gets `ips_seen`, `first_seen`, `last_seen`, `times_seen`
  and `last_port_scan`.

### `lan-watch.sh` — scan repeatedly

```sh
sudo ./lan-watch.sh [interval-seconds] [interface]
```

Runs `lan-spy.sh` every 300 seconds by default (start to start), then merges
all reports and regenerates the HTML page. Everything goes into `reports/`:

- `reports/lan-report-<timestamp>.json` (plus `-raw/` folders)
- `reports/lan-devices-merged.json`
- `reports/lan-devices.html`, which reloads itself every 60 seconds

Stop it with Ctrl+C. This is a stand-in until the scan runs from cron.

### `render-html.py` — HTML page

```sh
./render-html.py <report.json> [--refresh SECONDS] [--names FILE] > lan-devices.html
```

Renders a single report or a merged inventory as one self-contained HTML file
(no external resources, light and dark mode). It shows one row per device with
IP, vendor and names, MAC, open ports (with IoT hints), OS guess, latency and,
for merged input, how often and when the device was last seen. Devices missing
from the latest scan are greyed out.

To give devices your own names, copy `device-names.example.txt` to
`device-names.txt` and add one line per device: a MAC or IP address, then the
name. Named rows get a blue edge with the name above the vendor; online
devices without a name get a yellow edge, so you can see which still need one.
`render-html.py` (and so `lan-watch.sh`) picks the file up automatically;
`--names` points to another one. `device-names.txt` is git-ignored since it
lists your MACs.

## Typical use

```sh
sudo ./lan-watch.sh        # scan every 5 minutes, Ctrl+C to stop
xdg-open reports/lan-devices.html
```

Or by hand:

```sh
sudo ./lan-spy.sh          # run a few times, a few minutes apart
./merge-reports.py > lan-devices-merged.json
./render-html.py lan-devices-merged.json > lan-devices.html
```

Only scan networks you own or are allowed to scan.
