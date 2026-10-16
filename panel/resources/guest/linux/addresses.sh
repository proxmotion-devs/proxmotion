#!/bin/sh
# Proxmotion: the additional IP addresses of this server and a primary address
# that differs from cloud-init's (docs/adr/0009, 0010 and 0011 in the
# Proxmotion repository). Installed and run by Proxmotion through the QEMU
# guest agent; changes to this file are overwritten.
#
# /etc/proxmotion/addresses.vars (written by Proxmotion):
#   mac=52:54:00:12:34:56          MAC address of the interface (net0)
#   addresses=203.0.113.11/24      one line per additional address
#   primary_ipv4=203.0.113.12/24   the primary address (also primary_ipv6)
#   gateway_ipv4=203.0.113.1       its gateway (also gateway_ipv6)
#   cloudinit_ipv4=203.0.113.10/24 the address cloud-init configures (also cloudinit_ipv6)
#
# Adds the listed addresses, removes those this script added earlier and that
# are no longer listed (never others). When the primary differs from
# cloud-init's address, adds it, routes the default route through it and
# removes cloud-init's address unless it is still listed. Installs a systemd
# unit that runs it again at every boot. Safe to run any number of times.
set -eu

CONFIG_DIR=/etc/proxmotion
VARS="$CONFIG_DIR/addresses.vars"
WANTED="$CONFIG_DIR/addresses.list"
STATE_DIR=/var/lib/proxmotion
STATE="$STATE_DIR/addresses.applied"
UNIT=/etc/systemd/system/proxmotion-addresses.service
SELF=/usr/local/sbin/proxmotion-addresses

var() {
    sed -n "s/^$1=//p" "$VARS" | head -n 1
}

mac=$(var mac | tr 'A-F' 'a-f')
iface=""

for dev in /sys/class/net/*; do
    if [ -f "$dev/address" ] && [ "$(cat "$dev/address")" = "$mac" ]; then
        iface=$(basename "$dev")
        break
    fi
done

if [ -z "$mac" ] || [ -z "$iface" ]; then
    echo "No network interface with MAC address $mac." >&2
    exit 2
fi

mkdir -p "$STATE_DIR"
[ -f "$STATE" ] || : > "$STATE"
sed -n 's/^addresses=//p' "$VARS" > "$WANTED"

# Only addresses (hex digits, dots, colons and a prefix), nothing else reaches `ip`.
valid() {
    case "$1" in
        '' | *[!0-9a-fA-F.:/]*) return 1 ;;
        */*) return 0 ;;
        *) return 1 ;;
    esac
}

# A gateway: an address without a prefix.
valid_gateway() {
    case "$1" in
        '' | *[!0-9a-fA-F.:]*) return 1 ;;
        *) return 0 ;;
    esac
}

primaries="$(var primary_ipv4) $(var primary_ipv6)"

is_primary() {
    case " $primaries " in
        *" $1 "*) return 0 ;;
        *) return 1 ;;
    esac
}

while IFS= read -r address; do
    if valid "$address" && ! grep -qxF "$address" "$WANTED" && ! is_primary "$address"; then
        ip addr del "$address" dev "$iface" 2>/dev/null || true
    fi
done < "$STATE"

while IFS= read -r address; do
    if valid "$address"; then
        ip addr replace "$address" dev "$iface"
    fi
done < "$WANTED"

cp "$WANTED" "$STATE"

# The primary address of one IP version (4 or 6), when it is not the address
# cloud-init configured: cloud-init's settings are never changed on a running
# server, because it would treat the next boot as a new instance (ADR 0011).
apply_primary() {
    family=$1
    primary=$(var "primary_ipv$family")
    gateway=$(var "gateway_ipv$family")
    cloud=$(var "cloudinit_ipv$family")
    applied="$STATE_DIR/primary-ipv$family.applied"
    previous=$(cat "$applied" 2> /dev/null || true)

    if ! valid "$primary"; then
        return 0
    fi

    # The same address (cloud-init may know it with another prefix).
    if [ "${primary%/*}" = "${cloud%/*}" ]; then
        # Back to cloud-init's own address: restore its default route once.
        if [ -n "$previous" ]; then
            ip -"$family" addr replace "$cloud" dev "$iface"
            if valid_gateway "$gateway"; then
                ip -"$family" route replace default via "$gateway" dev "$iface" onlink src "${primary%/*}"
            fi
            if [ "${previous%/*}" != "${primary%/*}" ] && valid "$previous" && ! grep -qxF "$previous" "$WANTED"; then
                ip -"$family" addr del "$previous" dev "$iface" 2> /dev/null || true
            fi
            rm -f "$applied"
        fi
        return 0
    fi

    if [ "$family" = 4 ]; then
        # Removing the first address of a subnet would remove the others with it.
        sysctl -qw "net.ipv4.conf.$iface.promote_secondaries=1" || true
    fi

    ip -"$family" addr replace "$primary" dev "$iface"
    if valid_gateway "$gateway"; then
        ip -"$family" route replace default via "$gateway" dev "$iface" onlink src "${primary%/*}"
    fi

    for old in "$cloud" "$previous"; do
        if [ "${old%/*}" != "${primary%/*}" ] && valid "$old" && ! grep -qxF "$old" "$WANTED"; then
            ip -"$family" addr del "$old" dev "$iface" 2> /dev/null || true
        fi
    done

    # Removing an address can take routes with it.
    if valid_gateway "$gateway"; then
        ip -"$family" route replace default via "$gateway" dev "$iface" onlink src "${primary%/*}"
    fi

    echo "$primary" > "$applied"
}

apply_primary 4
apply_primary 6

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
