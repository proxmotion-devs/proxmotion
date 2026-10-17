#!/bin/sh
# Proxmotion: the server's hostname (docs/adr/0010 and 0012 in the Proxmotion
# repository). Installed and run by Proxmotion through the QEMU guest agent;
# changes to this file are overwritten.
#
# /etc/proxmotion/hostname.vars (written by Proxmotion):
#   hostname=web1.example.com        the new hostname
#   previous_hostname=web.example.com
#
# Sets the hostname, tells cloud-init to keep it (it would set the one from
# Proxmox's settings again at every boot), and points the 127.0.1.1 line of
# /etc/hosts at it. cloud-init rewrites /etc/hosts at every boot, so a systemd
# unit runs this again after cloud-init. Safe to run any number of times.
set -eu

VARS=/etc/proxmotion/hostname.vars
UNIT=/etc/systemd/system/proxmotion-hostname.service
SELF=/usr/local/sbin/proxmotion-hostname

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
    if command -v hostnamectl > /dev/null 2>&1; then
        hostnamectl set-hostname "$name"
    else
        echo "$name" > /etc/hostname
        hostname "$name"
    fi
fi

if [ -d /etc/cloud/cloud.cfg.d ]; then
    printf 'preserve_hostname: true\n' > /etc/cloud/cloud.cfg.d/99-proxmotion-hostname.cfg
fi

line="127.0.1.1 $name $short"
if grep -q '^127\.0\.1\.1[[:space:]]' /etc/hosts; then
    if ! grep -qx "$line" /etc/hosts; then
        sed -i "s/^127\.0\.1\.1[[:space:]].*/$line/" /etc/hosts
    fi
else
    echo "$line" >> /etc/hosts
fi

if command -v systemctl > /dev/null 2>&1 && [ ! -f "$UNIT" ]; then
    cat > "$UNIT" << EOF
[Unit]
Description=Proxmotion: hostname in /etc/hosts
After=cloud-init.service cloud-config.service

[Service]
Type=oneshot
ExecStart=/bin/sh $SELF
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
    systemctl enable proxmotion-hostname.service > /dev/null 2>&1
fi
