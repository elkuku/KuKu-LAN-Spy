#!/usr/bin/env bash
# Inventory devices on the local LAN and write a JSON report.
# Usage: sudo ./lan-spy.sh [interface] [output-file]
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

IFACE="${1:-$(ip route show default | awk '/default/ {print $5; exit}')}"
OUT="${2:-lan-report-$(date +%Y%m%d-%H%M%S).json}"

if [[ $EUID -ne 0 ]]; then
    echo "Please run as root (arp-scan and nmap OS detection need it): sudo $0" >&2
    exit 1
fi

CIDR="$(ip -4 -o addr show dev "$IFACE" | awk '{print $4; exit}')"
if [[ -z "$CIDR" ]]; then
    echo "No IPv4 address on interface $IFACE" >&2
    exit 1
fi
GATEWAY="$(ip route show default dev "$IFACE" | awk '{print $3; exit}')"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

log() { printf '[lan-spy] %s\n' "$*" >&2; }

log "Interface $IFACE, network $CIDR"

log "arp-scan..."
arp-scan --interface="$IFACE" --localnet --retry=3 --plain 2>/dev/null \
    | awk -F'\t' '{print $1 "\t" $2}' > "$TMP/arp.tsv"

log "nmap host discovery..."
nmap -sn -n -e "$IFACE" "$CIDR" -oX "$TMP/discovery.xml" > /dev/null 2>&1

# Union of everything that answered either scan, plus this machine
# (nmap -sn with -e does not list the local host).
{
    echo "${CIDR%/*}"
    cut -f1 "$TMP/arp.tsv"
    grep -o 'addr="[0-9.]*" addrtype="ipv4"' "$TMP/discovery.xml" | cut -d'"' -f2
} | sort -u -t. -k1,1n -k2,2n -k3,3n -k4,4n > "$TMP/hosts.txt"
mapfile -t HOSTS < "$TMP/hosts.txt"
log "${#HOSTS[@]} hosts found"

log "Reverse DNS and NetBIOS lookups..."
for ip in "${HOSTS[@]}"; do
    name="$(getent hosts "$ip" | awk 'NR==1 {print $2}')"
    [[ -n "$name" && "$name" != "$ip" ]] && printf '%s\t%s\n' "$ip" "$name"
done > "$TMP/dns.tsv"
for ip in "${HOSTS[@]}"; do
    nb="$(timeout 3 nmblookup -A "$ip" 2>/dev/null | awk '/<00>/ && !/GROUP/ {print $1; exit}')"
    [[ -n "$nb" ]] && printf '%s\t%s\n' "$ip" "$nb"
done > "$TMP/netbios.tsv"

log "mDNS browse (15s)..."
# Only records seen on the LAN interface, which drops the Docker bridges.
timeout 15 avahi-browse -artp 2>/dev/null \
    | awk -F';' -v ifc="$IFACE" '$1=="=" && $2==ifc && $3=="IPv4"' > "$TMP/mdns.txt"

# nmap's top 100 TCP ports (what -F scans) plus IoT control ports it leaves out:
# 6668 Tuya local API. (9999 TP-Link Kasa is already in the top 100.)
EXTRA_PORTS="6668"
PORTS="$( {
    awk '$2 ~ /\/tcp$/ {split($2, p, "/"); print $3, p[1]}' /usr/share/nmap/nmap-services \
        | sort -rg | head -100 | awk '{print $2}'
    tr ',' '\n' <<< "$EXTRA_PORTS"
} | sort -nu | paste -sd,)"

# Without limits one misbehaving device (tarpitting, half-open probes) can stall
# the scan for hours. Slow but healthy hosts finish in ~6 min, so --host-timeout
# drops only the stuck ones and keeps the XML valid. The outer timeout is a last
# resort if nmap itself hangs; its XML is then truncated and the deep results lost.
deep_scan() {
    timeout -k 30 25m \
        nmap -Pn -sV -O --osscan-guess -p "T:$PORTS" -T4 --version-light -e "$IFACE" \
            --max-retries 2 --host-timeout 10m \
            -iL "$1" -oX "$2" > /dev/null 2>&1 \
        || log "nmap deep scan of $1 exited with status $? (timed out?)"
}

# IPs nmap reported as up in an XML file (down hosts are omitted from it).
up_hosts() {
    grep -o '<status state="[a-z]*"\|<address addr="[0-9.]*"' "$1" \
        | awk -F'"' '/status/ {s = $2; next} s == "up" {print $2; s = ""}' | sort -u
}

log "nmap service + OS detection (top 100 ports + IoT ports, may take a few minutes)..."
# This machine is skipped: ARP to our own address never answers on $IFACE.
grep -vxF "${CIDR%/*}" "$TMP/hosts.txt" > "$TMP/targets.txt"
deep_scan "$TMP/targets.txt" "$TMP/deep.xml"

# Sleepy IoT devices often miss nmap's ARP check; give them a second chance.
grep -vxFf <(up_hosts "$TMP/deep.xml") "$TMP/targets.txt" > "$TMP/retry.txt"
if [[ -s "$TMP/retry.txt" ]]; then
    log "Retrying $(wc -l < "$TMP/retry.txt") host(s) that did not answer..."
    deep_scan "$TMP/retry.txt" "$TMP/deep-retry.xml"
fi

log "Listening ports on this machine..."
# Scanning ourselves over the LAN does not work, so ask the kernel instead.
ss -Hltnp > "$TMP/local-ss.txt" 2>/dev/null

log "Building JSON..."
# Build into the temp dir first, so a failed build never leaves an empty report behind.
if ! php "$SCRIPT_DIR/build-report.php" "$TMP" "$IFACE" "$CIDR" "$GATEWAY" "$(hostname)" > "$TMP/report.json"; then
    log "Building the report failed"
    exit 1
fi
mv "$TMP/report.json" "$OUT"

# Keep the raw nmap output next to the report for troubleshooting.
RAW="${OUT%.json}-raw"
mkdir -p "$RAW" && cp "$TMP"/*.xml "$RAW"/ 2>/dev/null

# Hand the report back to the user who ran sudo.
[[ -n "${SUDO_USER:-}" ]] && chown -R "$SUDO_USER": "$OUT" "$RAW"
log "Report written to $OUT"
