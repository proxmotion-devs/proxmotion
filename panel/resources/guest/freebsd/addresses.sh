#!/bin/sh
# Proxmotion: the additional IP addresses of a FreeBSD server and a primary
# address that differs from cloud-init's (docs/adr/0009, 0010, 0011 and 0048
# in the Proxmotion repository). Installed and run by Proxmotion through the
# QEMU guest agent; changes to this file are overwritten.
#
# /etc/proxmotion/addresses.vars (written by Proxmotion):
#   mac=52:54:00:12:34:56          MAC address of the interface (net0)
#   addresses=203.0.113.11/24      one line per additional address
#   primary_ipv4=203.0.113.12/24   the primary address (also primary_ipv6)
#   gateway_ipv4=203.0.113.1       its gateway (also gateway_ipv6)
#   cloudinit_ipv4=203.0.113.10/24 the address cloud-init configures (also cloudinit_ipv6)
#
# Adds the listed addresses as aliases, removes those this script added
# earlier and that are no longer listed (never others). When the primary
# differs from cloud-init's address, adds it, routes the default route
# through it (-ifa: the source address of IPv4 connections the server opens)
# and removes cloud-init's address unless it is still listed. Installs an
# rc.d script that runs it again at every boot. Safe to run any number of
# times.
set -eu

CONFIG_DIR=/etc/proxmotion
VARS="$CONFIG_DIR/addresses.vars"
WANTED="$CONFIG_DIR/addresses.list"
STATE_DIR=/var/lib/proxmotion
STATE="$STATE_DIR/addresses.applied"
RC=/usr/local/etc/rc.d/proxmotion_addresses
SELF=/usr/local/sbin/proxmotion-addresses

var() {
    sed -n "s/^$1=//p" "$VARS" | head -n 1
}

mac=$(var mac | tr 'A-F' 'a-f')
iface=""

if [ -n "$mac" ]; then
    for dev in $(ifconfig -l); do
        if [ "$(ifconfig "$dev" | awk '$1 == "ether" { print $2; exit }')" = "$mac" ]; then
            iface=$dev
            break
        fi
    done
fi

if [ -z "$mac" ] || [ -z "$iface" ]; then
    echo "No network interface with MAC address $mac." >&2
    exit 2
fi

mkdir -p "$STATE_DIR"
[ -f "$STATE" ] || : > "$STATE"
sed -n 's/^addresses=//p' "$VARS" > "$WANTED"

# Only addresses (hex digits, dots, colons and a prefix), nothing else reaches `ifconfig`.
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

family() {
    case "$1" in
        *:*) echo inet6 ;;
        *) echo inet ;;
    esac
}

add_address() {
    ifconfig "$iface" "$(family "$1")" "$1" alias
}

remove_address() {
    ifconfig "$iface" "$(family "$1")" "${1%/*}" -alias 2> /dev/null || true
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
        remove_address "$address"
    fi
done < "$STATE"

while IFS= read -r address; do
    if valid "$address"; then
        add_address "$address"
    fi
done < "$WANTED"

cp "$WANTED" "$STATE"

# The default route of one IP version (4 or 6) through $2, leaving from
# address $3. A routed /32 has its gateway outside its prefix, so the gateway
# is reached on the link first; a link-local IPv6 gateway needs the interface.
# (sh has no local variables: these names are used nowhere else.)
default_route() {
    route_via=$2
    route_from=${3%/*}

    if ! valid_gateway "$route_via"; then
        return 0
    fi

    if [ "$1" = 4 ]; then
        route_family=-inet
        case "$3" in
            */32) route -n -q add -inet -host "$route_via" -interface "$iface" 2> /dev/null || true ;;
        esac
    else
        route_family=-inet6
        case "$route_via" in
            [fF][eE]80:*) route_via="$route_via%$iface" ;;
        esac
    fi

    if ! route -n -q change "$route_family" default "$route_via" -ifa "$route_from" 2> /dev/null; then
        route -n -q delete "$route_family" default 2> /dev/null || true
        route -n -q add "$route_family" default "$route_via" -ifa "$route_from"
    fi
}

# The primary address of one IP version (4 or 6), when it is not the address
# cloud-init configured: cloud-init's settings are never changed on a running
# server, because it would treat the next boot as a new instance (ADR 0011).
apply_primary() {
    version=$1
    primary=$(var "primary_ipv$version")
    gateway=$(var "gateway_ipv$version")
    cloud=$(var "cloudinit_ipv$version")
    applied="$STATE_DIR/primary-ipv$version.applied"
    previous=$(cat "$applied" 2> /dev/null || true)

    if ! valid "$primary"; then
        return 0
    fi

    # The same address (cloud-init may know it with another prefix).
    if [ "${primary%/*}" = "${cloud%/*}" ]; then
        # Back to cloud-init's own address: restore its default route once.
        if [ -n "$previous" ]; then
            add_address "$cloud"
            default_route "$version" "$gateway" "$cloud"
            if [ "${previous%/*}" != "${primary%/*}" ] && valid "$previous" && ! grep -qxF "$previous" "$WANTED"; then
                remove_address "$previous"
            fi
            default_route "$version" "$gateway" "$cloud"
            rm -f "$applied"
        fi
        return 0
    fi

    add_address "$primary"
    default_route "$version" "$gateway" "$primary"

    for old in "$cloud" "$previous"; do
        if [ "${old%/*}" != "${primary%/*}" ] && valid "$old" && ! grep -qxF "$old" "$WANTED"; then
            remove_address "$old"
        fi
    done

    # Removing an address can take routes with it.
    default_route "$version" "$gateway" "$primary"

    echo "$primary" > "$applied"
}

apply_primary 4
apply_primary 6

if [ ! -f "$RC" ]; then
    mkdir -p "$(dirname "$RC")"
    cat > "$RC" << EOF
#!/bin/sh
#
# PROVIDE: proxmotion_addresses
# REQUIRE: NETWORKING
# KEYWORD: nojail
#
# Proxmotion: additional IP addresses.

. /etc/rc.subr

name=proxmotion_addresses
rcvar=proxmotion_addresses_enable
start_cmd="/bin/sh $SELF"
stop_cmd=":"

load_rc_config \$name
: "\${proxmotion_addresses_enable:=NO}"
run_rc_command "\$1"
EOF
    chmod 0555 "$RC"
fi

sysrc proxmotion_addresses_enable=YES > /dev/null
