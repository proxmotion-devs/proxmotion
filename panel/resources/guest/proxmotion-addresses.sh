#!/bin/sh
# Proxmotion: the additional IP addresses of this server (docs/adr/0009 in the
# Proxmotion repository). Installed and run by Proxmotion through the QEMU
# guest agent; changes to this file are overwritten.
#
# /etc/proxmotion/mac        MAC address of the interface (net0)
# /etc/proxmotion/addresses  one address/prefix per line, e.g. 203.0.113.10/24
#
# Adds the listed addresses, removes those this script added earlier and that
# are no longer listed (never others), and installs a systemd unit that runs
# it again at every boot. Safe to run any number of times.
set -eu

CONFIG_DIR=/etc/proxmotion
STATE_DIR=/var/lib/proxmotion
STATE="$STATE_DIR/addresses.applied"
UNIT=/etc/systemd/system/proxmotion-addresses.service
SELF=/usr/local/sbin/proxmotion-addresses

mac=$(tr 'A-F' 'a-f' < "$CONFIG_DIR/mac")
iface=""

for dev in /sys/class/net/*; do
    if [ -f "$dev/address" ] && [ "$(cat "$dev/address")" = "$mac" ]; then
        iface=$(basename "$dev")
        break
    fi
done

if [ -z "$iface" ]; then
    echo "No network interface with MAC address $mac." >&2
    exit 2
fi

mkdir -p "$STATE_DIR"
[ -f "$STATE" ] || : > "$STATE"
[ -f "$CONFIG_DIR/addresses" ] || : > "$CONFIG_DIR/addresses"

# Only addresses (hex digits, dots, colons and a prefix), nothing else reaches `ip`.
valid() {
    case "$1" in
        '' | *[!0-9a-fA-F.:/]*) return 1 ;;
        */*) return 0 ;;
        *) return 1 ;;
    esac
}

while IFS= read -r address; do
    if valid "$address" && ! grep -qxF "$address" "$CONFIG_DIR/addresses"; then
        ip addr del "$address" dev "$iface" 2>/dev/null || true
    fi
done < "$STATE"

while IFS= read -r address; do
    if valid "$address"; then
        ip addr replace "$address" dev "$iface"
    fi
done < "$CONFIG_DIR/addresses"

cp "$CONFIG_DIR/addresses" "$STATE"

if command -v systemctl > /dev/null 2>&1 && [ ! -f "$UNIT" ]; then
    cat > "$UNIT" << EOF
[Unit]
Description=Proxmotion: additional IP addresses
Wants=network-online.target
After=network-online.target

[Service]
Type=oneshot
ExecStart=/bin/sh $SELF
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
    systemctl enable proxmotion-addresses.service > /dev/null 2>&1
fi
