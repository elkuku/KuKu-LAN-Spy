#!/usr/bin/env python3
"""Merge lan-spy JSON reports into one device inventory.

Usage: ./merge-reports.py [report.json ...] > lan-devices-merged.json
With no arguments, merges every lan-report-*.json in the current directory.

Devices are matched by MAC address (by IP when there is no MAC). For each
device the newest report with a value wins; port and OS data come together
from the newest report in which the device was actually port-scanned.
"""
import ipaddress
import json
import sys
from pathlib import Path

SIMPLE_FIELDS = ("vendor", "mac_locally_administered", "hostname", "netbios", "role")
SCAN_FIELDS = ("ports", "ports_not_shown", "os", "network_distance", "latency_ms")


def load(paths):
    reports = []
    for path in paths:
        try:
            data = json.loads(Path(path).read_text())
        except (OSError, json.JSONDecodeError) as e:
            print(f"skipping {path}: {e}", file=sys.stderr)
            continue
        reports.append((data["scan"]["date"], str(path), data))
    return sorted(reports)  # oldest first, so newer values overwrite


def no_mac_key(d):
    """Key for a device without a MAC. Older reports have none for this machine,
    whose IP can change, so it is matched by hostname instead."""
    return f'this machine {d.get("hostname")}' if d.get("role") == "this machine" else d["ip"]


def merge(reports):
    devices = {}
    for date, path, data in reports:
        for d in data.get("devices", []):
            key = d.get("mac") or no_mac_key(d)
            # A device first seen without a MAC (this machine in older reports) gets it now.
            old = devices.get(no_mac_key(d))
            if key not in devices and old and old["mac"] is None:
                devices[key] = devices.pop(no_mac_key(d))
                devices[key]["mac"] = key
            m = devices.setdefault(key, {
                "ip": d["ip"], "mac": d.get("mac"), "ips_seen": [],
                **{f: None for f in SIMPLE_FIELDS},
                "first_seen": date, "last_seen": date, "times_seen": 0, "seen_by": [],
                "mdns": [], "last_port_scan": None,
                **{f: None for f in SCAN_FIELDS},
            })
            m["ip"] = d["ip"]
            if d["ip"] not in m["ips_seen"]:
                m["ips_seen"].append(d["ip"])
            m["last_seen"] = date
            m["times_seen"] += 1
            for src in d.get("seen_by") or []:
                if src not in m["seen_by"]:
                    m["seen_by"].append(src)
            for f in SIMPLE_FIELDS:
                if d.get(f) is not None:
                    m[f] = d[f]
            for entry in d.get("mdns") or []:
                if entry not in m["mdns"]:
                    m["mdns"].append(entry)
            # Older reports have no deep_scan field; there, a port list means it was scanned.
            # "local" is this machine, whose ports are read from the kernel instead.
            scanned = d.get("deep_scan", "ok" if d.get("ports") is not None else None) in ("ok", "local")
            if scanned:
                m["last_port_scan"] = date
                for f in SCAN_FIELDS:
                    m[f] = d.get(f)
    return sorted(devices.values(), key=lambda m: ipaddress.ip_address(m["ip"]))


def main():
    paths = sys.argv[1:] or sorted(Path(".").glob("lan-report-*.json"))
    reports = load(paths)
    if not reports:
        sys.exit("no reports to merge")
    newest = reports[-1][2]["scan"]
    out = {
        "merged_from": [{"file": p, "date": d, "devices": len(r.get("devices", []))} for d, p, r in reports],
        "network": newest.get("network"),
        "gateway": newest.get("gateway"),
        "local_ip": newest.get("local_ip"),
        "devices": merge(reports),
    }
    json.dump(out, sys.stdout, indent=2)
    print()


if __name__ == "__main__":
    main()
