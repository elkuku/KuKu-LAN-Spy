#!/usr/bin/env python3
"""Build the lan-spy JSON report from the raw scan files lan-spy.sh collects.

Usage: build-report.py <scan-dir> <interface> <cidr> <gateway> <hostname> > report.json

<scan-dir> holds arp.tsv, discovery.xml, dns.tsv, netbios.tsv, mdns.txt,
targets.txt, deep.xml, local-ss.txt and (optionally) deep-retry.xml.

This machine cannot scan itself over the LAN, so its MAC, OS and listening
ports are read locally (/sys, /etc/os-release and `ss -ltnp` output).
"""
import ipaddress, json, platform, re, socket, sys, xml.etree.ElementTree as ET
from datetime import datetime, timezone
from pathlib import Path

tmp, iface, cidr, gateway, myhost = sys.argv[1:6]
tmp = Path(tmp)
local_ip = cidr.split("/")[0]

# nmap names these after unrelated services; on a home LAN they usually mean this.
IOT_HINTS = {
    6668: "Tuya smart device local API",
    8009: "Google Cast",
    9999: "TP-Link Kasa smart device API",
}


def read_tsv(name):
    path = tmp / name
    return dict(l.split("\t", 1) for l in path.read_text().splitlines() if "\t" in l) if path.exists() else {}


def oui_db():
    db = {}
    try:
        for line in open("/usr/share/nmap/nmap-mac-prefixes", encoding="utf-8", errors="replace"):
            if line and not line.startswith("#"):
                prefix, _, vendor = line.strip().partition(" ")
                db[prefix.upper()] = vendor
    except OSError:
        pass
    return db


def parse_xml(name):
    try:
        return ET.parse(tmp / name).getroot()
    except (OSError, ET.ParseError):
        return None


def host_ip(h):
    a = h.find("address[@addrtype='ipv4']")
    return a.get("addr") if a is not None else None


devices = {}


def dev(ip):
    return devices.setdefault(ip, {"ip": ip, "mac": None, "vendor": None, "mac_locally_administered": None,
                                   "hostname": None, "netbios": None, "mdns": [], "role": None,
                                   "latency_ms": None, "seen_by": [], "deep_scan": "not scanned",
                                   "ports": None, "os": None})


for ip, mac in read_tsv("arp.tsv").items():
    d = dev(ip)
    d["mac"] = mac.strip().lower()
    d["seen_by"].append("arp-scan")

root = parse_xml("discovery.xml")
for h in (root.findall("host") if root is not None else []):
    ip = host_ip(h)
    if not ip or h.find("status").get("state") != "up":
        continue
    d = dev(ip)
    d["seen_by"].append("nmap")
    m = h.find("address[@addrtype='mac']")
    if m is not None:
        d["mac"] = m.get("addr").lower()
        d["vendor"] = m.get("vendor") or d["vendor"]

for ip, name in read_tsv("dns.tsv").items():
    dev(ip)["hostname"] = name
for ip, name in read_tsv("netbios.tsv").items():
    dev(ip)["netbios"] = name

mdns_path = tmp / "mdns.txt"
for line in (mdns_path.read_text().splitlines() if mdns_path.exists() else []):
    f = line.split(";")
    if len(f) < 10 or f[7] not in devices and f[7] != local_ip:
        continue
    txt = [t.strip('"') for t in f[9].split('" "') if t.strip('"')]
    entry = {"name": f[3], "service": f[4], "hostname": f[6], "port": int(f[8]) if f[8].isdigit() else None}
    if txt:
        entry["txt"] = txt
    if entry not in dev(f[7])["mdns"]:
        dev(f[7])["mdns"].append(entry)

targets = (tmp / "targets.txt").read_text().split() if (tmp / "targets.txt").exists() else []
for ip in targets:
    dev(ip)["deep_scan"] = "no response (device asleep or not answering ARP)"

deep_hosts = []
for name in ("deep.xml", "deep-retry.xml"):
    root = parse_xml(name)
    deep_hosts += root.findall("host") if root is not None else []

for h in deep_hosts:
    ip = host_ip(h)
    if not ip or h.find("status") is None or h.find("status").get("state") != "up":
        continue
    d = dev(ip)
    d["deep_scan"] = "ok"
    t = h.find("times")
    if t is not None and t.get("srtt") and d["latency_ms"] is None:
        d["latency_ms"] = round(int(t.get("srtt")) / 1000, 2)
    ports_el = h.find("ports")
    if ports_el is None:
        continue
    ports = []
    for p in ports_el.findall("port"):
        st, svc = p.find("state"), p.find("service")
        entry = {"port": int(p.get("portid")), "proto": p.get("protocol"), "state": st.get("state"),
                 "service": svc.get("name") if svc is not None else None, "version": None}
        if svc is not None:
            extra_info = f'({svc.get("extrainfo")})' if svc.get("extrainfo") else None
            ver = " ".join(filter(None, [svc.get("product"), svc.get("version"), extra_info]))
            entry["version"] = ver or None
        if entry["port"] in IOT_HINTS and entry["state"] == "open":
            entry["hint"] = IOT_HINTS[entry["port"]]
        ports.append(entry)
    extra = ports_el.find("extraports")
    d["ports"] = ports
    if extra is not None:
        d["ports_not_shown"] = f'{extra.get("count")} {extra.get("state")}'
    os_el = h.find("os")
    matches = os_el.findall("osmatch") if os_el is not None else []
    if matches:
        classes = [c for m in matches for c in m.findall("osclass")]
        d["os"] = {
            "best_guess": matches[0].get("name"),
            "accuracy": int(matches[0].get("accuracy")),
            "guesses": [{"name": m.get("name"), "accuracy": int(m.get("accuracy"))} for m in matches[:5]],
            "device_types": sorted({c.get("type") for c in classes if c.get("type")}),
            "cpe": sorted({c.text for c in (x.find("cpe") for x in classes) if c is not None and c.text}),
        }
    dist = h.find("distance")
    if dist is not None:
        d["network_distance"] = int(dist.get("value"))

def local_ports():
    """TCP ports this machine listens on that are reachable from the LAN."""
    path = tmp / "local-ss.txt"
    ports = {}
    for line in (path.read_text().splitlines() if path.exists() else []):
        f = line.split(None, 5)
        if len(f) < 5:
            continue
        host, _, port = f[3].rpartition(":")
        host = host.strip("[]").split("%")[0].removeprefix("::ffff:")
        if host not in ("*", "0.0.0.0", "::", local_ip) or not port.isdigit():
            continue
        procs = re.findall(r'\("([^"]+)"', f[5]) if len(f) > 5 else []
        entry = ports.setdefault(int(port), {"port": int(port), "proto": "tcp", "state": "open",
                                             "service": None, "version": None, "procs": []})
        entry["procs"] += [p for p in procs if p not in entry["procs"]]
    for p in ports.values():
        try:
            p["service"] = socket.getservbyport(p["port"], "tcp")
        except OSError:
            pass
        p["version"] = ", ".join(p.pop("procs")) or None
        if p["port"] in IOT_HINTS:
            p["hint"] = IOT_HINTS[p["port"]]
    return sorted(ports.values(), key=lambda p: p["port"])


def local_os():
    info = {}
    try:
        for line in open("/etc/os-release", encoding="utf-8"):
            k, _, v = line.strip().partition("=")
            info[k] = v.strip('"')
    except OSError:
        pass
    distro = info.get("PRETTY_NAME") or info.get("NAME") or platform.system()
    return {
        "best_guess": f"{distro} ({platform.system()} {platform.release()}, {platform.machine()})",
        "source": "local", "device_types": ["general purpose"],
    }


def fill_local(d):
    d["role"] = "this machine"
    d["deep_scan"] = "local"
    d["hostname"] = d["hostname"] or myhost
    if "local" not in d["seen_by"]:
        d["seen_by"].append("local")
    try:
        d["mac"] = Path(f"/sys/class/net/{iface}/address").read_text().strip().lower() or d["mac"]
    except OSError:
        pass
    d["ports"] = local_ports()
    d["os"] = local_os()


fill_local(dev(local_ip))
ouis = oui_db()
for ip, d in devices.items():
    if ip == gateway:
        d["role"] = "gateway"
    if d["mac"]:
        first = int(d["mac"][:2], 16)
        d["mac_locally_administered"] = bool(first & 0x02)
        if not d["vendor"] and not d["mac_locally_administered"]:
            d["vendor"] = ouis.get(d["mac"].replace(":", "")[:6].upper())

net = ipaddress.ip_interface(cidr).network
report = {
    "scan": {
        "date": datetime.now(timezone.utc).astimezone().isoformat(timespec="seconds"),
        "host": myhost, "interface": iface, "network": str(net),
        "local_ip": local_ip, "local_mac": devices[local_ip]["mac"],
        "gateway": gateway, "device_count": len(devices),
    },
    "devices": sorted(devices.values(), key=lambda d: ipaddress.ip_address(d["ip"])),
}
json.dump(report, sys.stdout, indent=2)
print()
