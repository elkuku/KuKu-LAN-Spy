#!/usr/bin/env bash
# Run a LAN scan every few minutes, then refresh the merged inventory and HTML page.
# Stand-in for a cron job.
# Usage: sudo ./lan-watch.sh [interval-seconds] [interface]
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INTERVAL="${1:-300}"
IFACE="${2:-}"
REPORTS="$SCRIPT_DIR/reports"
OUTPUT="$SCRIPT_DIR/output"
MERGED="$REPORTS/lan-devices-merged.json"
HTML="$OUTPUT/lan-devices.html"

if [[ $EUID -ne 0 ]]; then
    echo "Please run as root (lan-spy.sh needs it): sudo $0" >&2
    exit 1
fi

mkdir -p "$REPORTS" "$OUTPUT"
[[ -n "${SUDO_USER:-}" ]] && chown "$SUDO_USER": "$REPORTS" "$OUTPUT"

log() { printf '[lan-watch %s] %s\n' "$(date +%H:%M:%S)" "$*" >&2; }

trap 'log "Stopped."; exit 0' INT TERM

while true; do
    start=$(date +%s)
    log "Scanning..."
    if "$SCRIPT_DIR/lan-spy.sh" "$IFACE" "$REPORTS/lan-report-$(date +%Y%m%d-%H%M%S).json"; then
        php "$SCRIPT_DIR/merge-reports.php" "$REPORTS"/lan-report-*.json > "$MERGED.tmp" \
            && mv "$MERGED.tmp" "$MERGED"
        php "$SCRIPT_DIR/render-html.php" "$MERGED" --refresh 60 > "$HTML.tmp" \
            && mv "$HTML.tmp" "$HTML"
        [[ -n "${SUDO_USER:-}" ]] && chown "$SUDO_USER": "$MERGED" "$HTML"
        log "Updated $HTML"
    else
        log "Scan failed, will retry next round."
    fi

    # Keep a steady start-to-start interval regardless of how long the scan took.
    wait_for=$((INTERVAL - ($(date +%s) - start)))
    if ((wait_for > 0)); then
        log "Next scan in ${wait_for}s (Ctrl+C to stop)"
        sleep "$wait_for"
    fi
done
