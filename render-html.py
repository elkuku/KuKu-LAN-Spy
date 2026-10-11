#!/usr/bin/env python3
"""Render a lan-spy report or merged inventory as a standalone HTML page.

Usage: ./render-html.py <report.json> [--refresh SECONDS] [--names FILE] > lan-devices.html

Accepts the output of lan-spy.sh (single scan) or merge-reports.py (merged).
Custom device names are read from device-names.txt next to this script, if it
exists (see device-names.example.txt).
"""
import argparse
import ipaddress
import json
import sys
from datetime import datetime
from html import escape
from pathlib import Path

DEFAULT_NAMES = Path(__file__).resolve().parent / "device-names.txt"

CSS = """
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
.toolbar { display: flex; justify-content: flex-end; margin-bottom: 8px; }
.toolbar button { font: inherit; font-size: 12px; color: var(--text); background: var(--card);
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
"""

SCRIPT = """
const KEY = "lan-spy-open";
const rows = [...document.querySelectorAll("tbody tr")];
let open;
try { open = new Set(JSON.parse(sessionStorage.getItem(KEY) || "[]")); } catch (e) { open = new Set(); }
const save = () => { try { sessionStorage.setItem(KEY, JSON.stringify([...open])); } catch (e) {} };
const toggleAll = document.getElementById("toggle-all");
function set(tr, on) {
  tr.classList.toggle("open", on);
  tr.setAttribute("aria-expanded", on);
  on ? open.add(tr.dataset.key) : open.delete(tr.dataset.key);
}
function sync() {
  toggleAll.textContent = rows.every(r => r.classList.contains("open")) ? "Collapse all" : "Expand all";
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
  rows.forEach(tr => set(tr, on)); sync();
});
sync();
"""

# Inline SVG bodies (24x24 viewBox, stroked) and a label per device type.
ICONS = {
    "router": ("Router", '<rect x="2" y="14" width="20" height="7" rx="2"/><path d="M6 17.5h.01M10 17.5h.01M15 14V9"/>'
               '<path d="M11.5 6.5a5 5 0 0 1 7 0M9 4a8.5 8.5 0 0 1 12 0"/>'),
    "wifi": ("Access point / extender", '<path d="M2 9a14 14 0 0 1 20 0M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0M12 20h.01"/>'),
    "computer": ("Computer", '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>'),
    "laptop": ("Laptop", '<rect x="4" y="4" width="16" height="11" rx="1.5"/><path d="M2 19h20"/>'),
    "server": ("Server / NAS", '<rect x="2" y="3" width="20" height="8" rx="2"/><rect x="2" y="13" width="20" height="8" rx="2"/>'
               '<path d="M6 7h.01M6 17h.01"/>'),
    "phone": ("Phone", '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>'),
    "tablet": ("Tablet", '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M11 18h2"/>'),
    "tv": ("TV / streaming", '<rect x="2" y="7" width="20" height="13" rx="2"/><path d="m7 2 5 5 5-5"/>'),
    "camera": ("Camera", '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3z"/>'
               '<circle cx="12" cy="13" r="3"/>'),
    "speaker": ("Speaker", '<rect x="5" y="2" width="14" height="20" rx="2"/><circle cx="12" cy="14" r="4"/><path d="M12 6h.01"/>'),
    "printer": ("Printer", '<path d="M6 9V2h12v7"/><rect x="2" y="9" width="20" height="9" rx="2"/><rect x="6" y="14" width="12" height="8"/>'),
    "bulb": ("Light", '<path d="M9 18h6M10 22h4"/>'
             '<path d="M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.2 1 2V18h6v-1.3c0-.8.4-1.5 1-2A7 7 0 0 0 12 2z"/>'),
    "plug": ("Smart plug / switch", '<path d="M9 2v6M15 2v6M12 18v4M6 8h12v4a6 6 0 0 1-12 0z"/>'),
    "unknown": ("Unknown device", '<circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M12 17h.01"/>'),
}

# Words in a custom name that give away the device type, checked in this order.
NAME_KEYWORDS = [
    ("camera", {"cam", "camera", "cámara", "camara", "webcam", "doorbell", "timbre"}),
    ("bulb", {"light", "lamp", "bulb", "luz", "foco", "lampara", "lámpara"}),
    ("phone", {"phone", "iphone", "pixel", "galaxy", "teléfono", "telefono", "celular", "móvil", "movil", "handy"}),
    ("tablet", {"tablet", "ipad"}),
    ("tv", {"tv", "television", "televisión", "chromecast", "roku", "firestick"}),
    ("laptop", {"laptop", "notebook", "macbook", "thinkpad"}),
    ("computer", {"pc", "desktop", "computer", "computadora", "imac"}),
    ("server", {"server", "servidor", "nas", "raspberry", "pi"}),
    ("printer", {"printer", "impresora", "drucker"}),
    ("speaker", {"speaker", "echo", "alexa", "sonos", "parlante"}),
    ("router", {"router", "gateway", "modem", "módem"}),
    ("wifi", {"extender", "repeater", "repetidor", "mesh", "ap"}),
    ("plug", {"plug", "enchufe", "outlet", "socket"}),
]


def fmt_time(iso):
    if not iso:
        return ""
    try:
        return datetime.fromisoformat(iso).strftime("%Y-%m-%d %H:%M")
    except ValueError:
        return iso


def ports_html(d):
    ports = d.get("ports")
    if ports is None:
        return '<span class="muted">not scanned</span>'
    chips = []
    for p in ports:
        label = f'{p["port"]}/{p.get("service") or "?"}'
        title = " — ".join(filter(None, [p.get("version"), p.get("hint")]))
        cls = "chip"
        if p.get("state") != "open":
            cls += " filtered more"
            label += f' ({p.get("state")})'
        elif p.get("hint"):
            cls += " hint"
            label = f'{p["port"]} {p["hint"]}'
        chips.append(f'<span class="{cls}" title="{escape(title)}">{escape(label)}</span>')
    rest = d.get("ports_not_shown")
    if rest:
        chips.append(f'<div class="muted more">{escape(rest)}</div>')
    if not any(p.get("state") == "open" for p in ports):
        chips.insert(0, '<span class="muted less">none open</span>')
    return "".join(chips)


def os_html(d):
    os = d.get("os") or {}
    name = os.get("best_guess") or os.get("details")
    if not name:
        return '<span class="muted">unknown</span>'
    out = escape(name)
    if os.get("accuracy"):
        out += f' <span class="muted">{os["accuracy"]}%</span>'
    if os.get("source") == "local":
        out += '<div class="muted more">read locally</div>'
    elif not any(p.get("state") == "open" for p in d.get("ports") or []):
        out += '<div class="badge more">unreliable: no open ports</div>'
    return out


def load_names(path):
    """Read custom device names: one '<mac-or-ip> [type] <name>' per line, '#' lines are comments.

    Returns {address: (name or None, type or None)}.
    """
    names = {}
    for line in Path(path).read_text().splitlines():
        parts = line.split(None, 1)
        if len(parts) != 2 or parts[0].startswith("#"):
            continue
        name, kind = parts[1].strip(), None
        if name.startswith("[") and "]" in name:
            kind, name = name[1:].split("]", 1)
            kind, name = kind.strip().lower(), name.strip()
            if kind not in ICONS:
                sys.exit(f"unknown device type [{kind}] in {path}; use one of: {', '.join(ICONS)}")
        names[parts[0].lower().replace("-", ":")] = (name or None, kind)
    return names


def custom_name(d, names):
    for key in (d.get("mac"), d["ip"]):
        if key and key.lower() in names:
            return names[key.lower()]
    return None, None


def device_type(d, name):
    """Guess an icon key from the scan data and the custom name, strongest signal first."""
    if d.get("role") == "gateway":
        return "router"
    if d.get("role") == "this machine":
        return "computer"
    open_ports = {p["port"]: p for p in d.get("ports") or [] if p.get("state") == "open"}
    # avahi-browse may report "_ipp._tcp" or its readable name "Internet Printer", so match loosely.
    mdns = " ".join(m.get("service") or "" for m in d.get("mdns") or []).lower()
    announces = lambda *words: any(w in mdns for w in words)
    if 554 in open_ports or any("rtsp" in (p.get("service") or "") for p in open_ports.values()):
        return "camera"
    if 8009 in open_ports or announces("googlecast", "google cast", "airplay", "androidtvremote"):
        return "tv"
    if {631, 9100} & open_ports.keys() or announces("_ipp", "printer", "pdl-datastream"):
        return "printer"
    if name:
        words = set(name.lower().replace("-", " ").replace("_", " ").split())
        for kind, keywords in NAME_KEYWORDS:
            if words & keywords:
                return kind
    if announces("raop", "airtunes", "spotify", "sonos"):
        return "speaker"
    vendor = (d.get("vendor") or "").lower()
    if {6668, 9999} & open_ports.keys() or any(v in vendor for v in ("tuya", "espressif", "shelly", "sonoff")):
        return "plug"
    if 53 in open_ports:
        return "wifi"
    os_name = ((d.get("os") or {}).get("best_guess") or "").lower()
    if "android" in os_name or "ios" in os_name.split():
        return "phone"
    if "windows" in os_name or "macos" in os_name or "mac os" in os_name:
        return "computer"
    if d.get("mac_locally_administered"):
        return "phone"
    return "unknown"


def icon_html(kind):
    label, body = ICONS[kind]
    return (f'<span class="icon" title="{escape(label)}">'
            f'<svg viewBox="0 0 24 24" role="img" aria-label="{escape(label)}">{body}</svg></span>')


def device_row(d, online, merged, total_reports, custom_names):
    names = [d.get("hostname"), d.get("netbios")] + [m.get("hostname") for m in d.get("mdns") or []]
    names = list(dict.fromkeys(n for n in names if n))
    who = escape(d.get("vendor") or ("Private MAC" if d.get("mac_locally_administered") else "Unknown vendor"))
    extra = [escape(n) for n in names]
    custom, kind = custom_name(d, custom_names)
    icon = icon_html(kind or device_type(d, custom))
    if custom:
        extra.insert(0, who)
        who = f'<span class="name">{escape(custom)}</span>'
    if d.get("role"):
        extra.insert(0, f"<b>{escape(d['role'])}</b>")
    services = sorted({m.get("service") for m in d.get("mdns") or [] if m.get("service")})
    if services:
        extra.append("mDNS: " + escape(", ".join(services)))

    mac = escape(d.get("mac") or "—")
    if d.get("mac_locally_administered"):
        mac += '<div class="muted more">randomized</div>'

    # Blue marker for named devices, yellow for online ones that still need a name.
    row_class = " ".join(filter(None, ["named" if custom else "unnamed" if online else "",
                                       "" if online else "offline"]))

    latency = f'{d["latency_ms"]:g} ms' if d.get("latency_ms") is not None else "—"
    if merged:
        seen = f'{d.get("times_seen", 0)}/{total_reports}<div class="muted more">last {escape(fmt_time(d.get("last_seen")))}</div>'
    else:
        seen = escape(d.get("deep_scan") or "")

    return f"""<tr class="{row_class}" data-key="{escape(d.get("mac") or d["ip"])}" tabindex="0" aria-expanded="false">
<td class="mono"><span class="chev">›</span><span class="dot{' up' if online else ''}"></span>{escape(d["ip"])}</td>
<td><div class="device">{icon}<div>{who}<div class="muted more">{"<br>".join(extra)}</div></div></div></td>
<td class="mono">{mac}</td>
<td>{ports_html(d)}</td>
<td>{os_html(d)}</td>
<td class="mono">{latency}</td>
<td>{seen}</td>
</tr>"""


def render(data, refresh, custom_names):
    merged = "merged_from" in data
    if merged:
        reports = data["merged_from"]
        latest = max(r["date"] for r in reports)
        total = len(reports)
        network, gateway = data.get("network"), data.get("gateway")
    else:
        latest = data["scan"]["date"]
        total = 1
        network, gateway = data["scan"].get("network"), data["scan"].get("gateway")

    devices = sorted(data["devices"], key=lambda d: ipaddress.ip_address(d["ip"]))
    is_online = (lambda d: d.get("last_seen") == latest) if merged else (lambda d: True)
    online = sum(1 for d in devices if is_online(d))
    open_ports = sum(1 for d in devices for p in d.get("ports") or [] if p.get("state") == "open")

    rows = "\n".join(device_row(d, is_online(d), merged, total, custom_names) for d in devices)
    meta_refresh = f'<meta http-equiv="refresh" content="{refresh}">' if refresh else ""
    stats = [
        (len(devices), "devices known" if merged else "devices"),
        (online, "in latest scan"),
        (open_ports, "open ports"),
    ]
    if merged:
        stats.append((total, "scans merged"))
    stats_html = "".join(f'<div class="stat"><b>{v}</b><span>{escape(k)}</span></div>' for v, k in stats)

    return f"""<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
{meta_refresh}
<title>LAN Devices</title>
<style>{CSS}</style>
</head>
<body>
<main>
<h1>LAN Devices</h1>
<div class="sub">{escape(network or "")} · gateway {escape(gateway or "?")} · latest scan {escape(fmt_time(latest))}</div>
<div class="stats">{stats_html}</div>
<div class="toolbar"><button type="button" id="toggle-all">Expand all</button></div>
<div class="wrap">
<table>
<colgroup><col style="width:165px"><col style="width:20%"><col style="width:168px"><col>
<col style="width:17%"><col style="width:95px"><col style="width:90px"></colgroup>
<thead><tr><th>IP</th><th>Device</th><th>MAC</th><th>Ports</th><th>OS guess</th><th>Latency</th><th>{"Seen" if merged else "Port scan"}</th></tr></thead>
<tbody>
{rows}
</tbody>
</table>
</div>
<p class="muted">Generated {escape(datetime.now().strftime("%Y-%m-%d %H:%M:%S"))} by render-html.py.
Click a row for details. Greyed-out rows were not seen in the latest scan; a yellow edge marks online
devices without a custom name.</p>
</main>
<script>{SCRIPT}</script>
</body>
</html>
"""


def main():
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("report", help="lan-report-*.json or lan-devices-merged.json")
    ap.add_argument("--refresh", type=int, default=0, help="auto-reload the page every N seconds")
    ap.add_argument("--names", default=DEFAULT_NAMES,
                    help=f"custom device names file (default: {DEFAULT_NAMES.name} next to this script, if present)")
    args = ap.parse_args()
    data = json.loads(Path(args.report).read_text())
    names_path = Path(args.names)
    if names_path != DEFAULT_NAMES and not names_path.exists():
        sys.exit(f"names file not found: {names_path}")
    custom_names = load_names(names_path) if names_path.exists() else {}
    sys.stdout.write(render(data, args.refresh, custom_names))


if __name__ == "__main__":
    main()
