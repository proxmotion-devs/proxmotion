#!/bin/sh
# Proxmotion: the hostname of a FreeBSD server (docs/adr/0010, 0012 and 0048
# in the Proxmotion repository). Installed and run by Proxmotion through the
# QEMU guest agent; changes to this file are overwritten.
#
# /etc/proxmotion/hostname.vars (written by Proxmotion):
#   hostname=web1.example.com        the new hostname
#   previous_hostname=web.example.com
#
# Sets the hostname now and in /etc/rc.conf, tells cloud-init to keep it (it
# would set the one from Proxmox's settings again at every boot), and points
# the lines of /etc/hosts that name the host on 127.0.0.1 and ::1 at it.
# cloud-init rewrites /etc/hosts at every boot, so an rc.d script runs this
# again after cloud-init. Safe to run any number of times.
set -eu

VARS=/etc/proxmotion/hostname.vars
SELF=/usr/local/sbin/proxmotion-hostname
RC=/usr/local/etc/rc.d/proxmotion_hostname
CLOUD_CFG=/usr/local/etc/cloud/cloud.cfg.d
HOSTS=/etc/hosts

name=$(sed -n 's/^hostname=//p' "$VARS" | head -n 1)

# Only hostname characters reach the commands and files below.
case "$name" in
    '' | -* | *[!a-zA-Z0-9.-]*)
        echo "Invalid hostname: $name" >&2
        exit 2
        ;;
esac

short=${name%%.*}

if [ "$(hostname)" != "$name" ]; then
    hostname "$name"
fi

sysrc hostname="$name" > /dev/null

if [ -d "$CLOUD_CFG" ]; then
    printf 'preserve_hostname: true\n' > "$CLOUD_CFG/99-proxmotion-hostname.cfg"
fi

# cloud-init's FreeBSD template writes "127.0.0.1 <fqdn> <name>" and the same
# for ::1; lines naming localhost stay as they are.
new=$(mktemp /etc/hosts.proxmotion.XXXXXX)
trap 'rm -f "$new"' EXIT

awk -v name="$name" -v short="$short" '
    ($1 == "127.0.0.1" || $1 == "::1") && NF > 1 && $2 !~ /^localhost/ {
        print $1 " " name " " short
        found = 1
        next
    }
    { print }
    END { if (!found) print "127.0.0.1 " name " " short }
' "$HOSTS" > "$new"

if ! cmp -s "$new" "$HOSTS"; then
    cat "$new" > "$HOSTS"
fi

if [ ! -f "$RC" ]; then
    mkdir -p "$(dirname "$RC")"
    cat > "$RC" << EOF
#!/bin/sh
#
# PROVIDE: proxmotion_hostname
# REQUIRE: cloudinit
# KEYWORD: nojail
#
# Proxmotion: the hostname in /etc/hosts after cloud-init rewrote it.

. /etc/rc.subr

name=proxmotion_hostname
rcvar=proxmotion_hostname_enable
start_cmd="/bin/sh $SELF"
stop_cmd=":"

load_rc_config \$name
: "\${proxmotion_hostname_enable:=NO}"
run_rc_command "\$1"
EOF
    chmod 0555 "$RC"
fi

sysrc proxmotion_hostname_enable=YES > /dev/null
