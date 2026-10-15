# kuku-lan-spy

Inventory the devices on your local network and write the results as JSON
and as an HTML page.

## Requirements

- `arp-scan`, `nmap`
- `avahi-browse` (avahi) for mDNS/Bonjour discovery
- `nmblookup` (smbclient) for NetBIOS names
- PHP 8.5 or newer (CLI, with the bundled SimpleXML, mbstring and ctype extensions)
- [Composer](https://getcomposer.org/)

On Arch Linux: `sudo pacman -S arp-scan nmap avahi smbclient php composer`

Then install the PHP autoloader once, in the project folder:

```sh
composer install --no-dev
```

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
6. **`build-report.php`** turns the collected data into the JSON report.

The raw nmap XML is kept in `lan-report-<timestamp>-raw/` for troubleshooting.
When run through `sudo`, the report and raw folder are handed back to the
calling user.

### `build-report.php` — build the JSON report

Called by `lan-spy.sh`; you normally don't run it directly.

```sh
./build-report.php <scan-dir> <interface> <cidr> <gateway> <hostname> > report.json
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

### `merge-reports.php` — combine several reports

```sh
./merge-reports.php [report.json ...] > lan-devices-merged.json
```

With no arguments, merges every `lan-report-*.json` in the current directory.
Because sleepy devices answer some runs and not others, merging a few scans
gives a more complete picture than any single one.

- Devices are matched by MAC address (by IP when there is none).
- For names, vendor and role, the newest report with a value wins.
- Ports, OS, latency and distance are taken together from the newest report
  in which the device was actually port-scanned.
- Each device also gets `ips_seen`, `first_seen`, `last_seen`, `times_seen`,
  `seen_in` (the indexes in `merged_from` of the scans that saw it) and
  `last_port_scan`.

### `lan-watch.sh` — scan repeatedly

```sh
sudo ./lan-watch.sh [interval-seconds] [interface]
```

Runs `lan-spy.sh` every 300 seconds by default (start to start), then merges
all reports and regenerates the HTML page:

- `reports/lan-report-<timestamp>.json` (plus `-raw/` folders)
- `reports/lan-devices-merged.json`
- `output/lan-devices.html`, which reloads itself every 60 seconds

Stop it with Ctrl+C. This is a stand-in until the scan runs from cron.

### `render-html.php` — HTML page

```sh
./render-html.php <report.json> [--refresh SECONDS] [--names FILE] > lan-devices.html
```

Renders a single report or a merged inventory as one self-contained HTML file
(no external resources, light and dark mode). It shows one row per device with
IP, vendor and names, MAC, open ports (with IoT hints), OS guess, latency and,
for merged input, how often and when the device was last seen. Devices missing
from the latest scan are greyed out.

The page is built from the files in `templates/`: the [Twig](https://twig.symfony.com/)
templates `page.html.twig`, `device-row.html.twig` and `history.html.twig` hold
the markup, and `style.css` and `script.js` are inlined into the page. Edit
those to change how the page looks.

Each row shows one line; click it (or press Enter) to expand the details:
hostnames and mDNS services, filtered ports, OS notes and when the device was
last seen. "Expand all" opens every row, and open rows stay open across the
page's auto-refresh.

To give devices your own names, copy `device-names.example.txt` to
`device-names.txt` and add one line per device: a MAC or IP address, then the
name. Named rows get a blue edge with the name above the vendor; online
devices without a name get a yellow edge, so you can see which still need one.

Each device also gets an icon (router, camera, phone, TV, smart plug, ...),
guessed from the strongest signal available: the gateway and this machine,
then open ports and mDNS services (RTSP means a camera, Google Cast a TV,
IPP a printer), then words in the custom name (`Cam ...`, `Pixel ...`,
`Luz ...`), then weaker hints such as a Tuya vendor or a randomized MAC. If
the guess is wrong, set the type in brackets before the name, e.g.
`aa:bb:cc:dd:ee:ff [camera] Front door`; `device-names.example.txt` lists the
types. The "Type" menu above the table shows only devices of one type; it
lists the types present with their counts. The "MAC" box next to it shows only
devices whose MAC contains what you type; separators and case don't matter, so
`584b` finds `58:4b:...`. Both filters combine, turn yellow while active and are
kept across the page's auto-refresh; if no device matches, the table says so
and offers a button to clear them. The button next to each MAC copies it to
the clipboard.
`render-html.php` (and so `lan-watch.sh`) picks the file up automatically;
`--names` points to another one. `device-names.txt` is git-ignored since it
lists your MACs.

For a merged inventory, an "Online history" section below the table draws one
strip per device with a slot per scan, oldest on the left: a full green bar
where the scan saw the device, a thin grey line where it did not. The strips
line up, so a scan that missed several devices at once shows as a column.
Pauses of more than an hour between scans break the strips. Hover a strip for
the scan's date and the device's state; the type and MAC filters apply here
too.

## Code layout

The three PHP scripts are thin entry points; the code lives in `src/`, one
class per file under the `LanSpy\` namespace, loaded by Composer's autoloader:

- `src/Build/` — `build-report.php`: reads the scan files into the JSON report
- `src/Merge/` — `merge-reports.php`: merges reports into the inventory
- `src/Render/` — `render-html.php`: turns a report into the HTML page, using
  the Twig templates in `templates/`
- `src/Util/` — shared helpers (IP sorting, JSON output, timezone, strings)

## Development

The code checks need a few more tools: `composer install` (without
`--no-dev`) installs the PHP ones, `npm install` installs
[Biome](https://biomejs.dev/), and ShellCheck and shfmt come from the system
(on Arch Linux: `sudo pacman -S nodejs npm shellcheck shfmt`).

```sh
composer check       # all of the below, without changing anything
composer cs          # PHP coding style (PHP-CS-Fixer, PER-CS)
composer rector      # suggested PHP modernizations (Rector)
composer analyse     # PHP static analysis (PHPStan, level 8)
composer twig        # Twig template style (Twig-CS-Fixer)
composer biome       # lint and formatting of templates/*.js and *.css (Biome)
composer shell       # lint (ShellCheck) and formatting (shfmt) of the shell scripts

composer fix         # apply all the fixes below
composer cs:fix      # each check above has a :fix script, except analyse
```

PHP-CS-Fixer leaves the templates alone, and Twig-CS-Fixer only touches the
inside of the Twig tags, since the whitespace around them ends up in the
HTML. shfmt reads its settings from `.editorconfig`.

## Typical use

```sh
sudo ./lan-watch.sh        # scan every 5 minutes, Ctrl+C to stop
xdg-open output/lan-devices.html
```

Or by hand:

```sh
sudo ./lan-spy.sh          # run a few times, a few minutes apart
./merge-reports.php > lan-devices-merged.json
./render-html.php lan-devices-merged.json > lan-devices.html
```

Only scan networks you own or are allowed to scan.
