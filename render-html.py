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
  --up: #16a34a; --down: #9ca3af; --warn: #b45309; --name: #1d4ed8;
}
@media (prefers-color-scheme: dark) {
  :root {
    --bg: #111317; --card: #1a1d23; --text: #e6e8eb; --muted: #9aa1ab; --border: #2b3038;
    --chip: #262b45; --chip-text: #c7d2fe; --hint: #10302a; --hint-text: #a7f3d0;
    --up: #22c55e; --down: #4b5563; --warn: #f59e0b; --name: #93c5fd;
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
table { border-collapse: collapse; width: 100%; }
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
"""


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
            cls += " filtered"
            label += f' ({p.get("state")})'
        elif p.get("hint"):
            cls += " hint"
            label = f'{p["port"]} {p["hint"]}'
        chips.append(f'<span class="{cls}" title="{escape(title)}">{escape(label)}</span>')
    rest = d.get("ports_not_shown")
    if rest:
        chips.append(f'<div class="muted">{escape(rest)}</div>')
    return "".join(chips) or '<span class="muted">none</span>'


def os_html(d):
    os = d.get("os") or {}
    name = os.get("best_guess") or os.get("details")
    if not name:
        return '<span class="muted">unknown</span>'
    out = escape(name)
    if os.get("accuracy"):
        out += f' <span class="muted">{os["accuracy"]}%</span>'
    if os.get("source") == "local":
        out += '<div class="muted">read locally</div>'
    elif not any(p.get("state") == "open" for p in d.get("ports") or []):
        out += '<div class="badge">unreliable: no open ports</div>'
    return out


def load_names(path):
    """Read custom device names: one '<mac-or-ip> <name>' per line, '#' lines are comments."""
    names = {}
    for line in Path(path).read_text().splitlines():
        parts = line.split(None, 1)
        if len(parts) == 2 and not parts[0].startswith("#"):
            names[parts[0].lower().replace("-", ":")] = parts[1].strip()
    return names


def custom_name(d, names):
    for key in (d.get("mac"), d["ip"]):
        if key and key.lower() in names:
            return names[key.lower()]
    return None


def device_row(d, online, merged, total_reports, custom_names):
    names = [d.get("hostname"), d.get("netbios")] + [m.get("hostname") for m in d.get("mdns") or []]
    names = list(dict.fromkeys(n for n in names if n))
    who = escape(d.get("vendor") or ("Private MAC" if d.get("mac_locally_administered") else "Unknown vendor"))
    extra = [escape(n) for n in names]
    custom = custom_name(d, custom_names)
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
        mac += '<div class="muted">randomized</div>'

    latency = f'{d["latency_ms"]:g} ms' if d.get("latency_ms") is not None else "—"
    if merged:
        seen = f'{d.get("times_seen", 0)}/{total_reports}<div class="muted">last {escape(fmt_time(d.get("last_seen")))}</div>'
    else:
        seen = escape(d.get("deep_scan") or "")

    return f"""<tr class="{' '.join(filter(None, ['' if online else 'offline', 'named' if custom else '']))}">
<td class="mono"><span class="dot{' up' if online else ''}"></span>{escape(d["ip"])}</td>
<td>{who}<div class="muted">{"<br>".join(extra)}</div></td>
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
<div class="wrap">
<table>
<thead><tr><th>IP</th><th>Device</th><th>MAC</th><th>Ports</th><th>OS guess</th><th>Latency</th><th>{"Seen" if merged else "Port scan"}</th></tr></thead>
<tbody>
{rows}
</tbody>
</table>
</div>
<p class="muted">Generated {escape(datetime.now().strftime("%Y-%m-%d %H:%M:%S"))} by render-html.py.
Greyed-out rows were not seen in the latest scan.</p>
</main>
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
