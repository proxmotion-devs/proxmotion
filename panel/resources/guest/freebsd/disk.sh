#!/bin/sh
# Proxmotion: grow the root file system of a FreeBSD server to the size of its
# disk after an upgrade (docs/adr/0010 and 0048 in the Proxmotion repository).
# Installed and run by Proxmotion through the QEMU guest agent; changes to
# this file are overwritten. An OS template can replace it in the admin panel.
#
# /etc/proxmotion/disk.vars (written by Proxmotion):
#   disk_mib=20480                 the new size of the disk
#
# Runs FreeBSD's own growfs service, the one cloud-init runs on the first
# boot: it recovers the GPT, grows the last partition and then UFS (growfs) or
# ZFS (zpool online -e). growfs_swap_size=0 keeps it from adding a swap
# partition after root, which would stop the next upgrade. Safe to run any
# number of times.
set -eu

if [ ! -f /etc/rc.d/growfs ]; then
    echo "/etc/rc.d/growfs is missing: grow the root file system by hand." >&2
    exit 2
fi

sysrc growfs_swap_size=0 > /dev/null

# CAM disks (VirtIO SCSI, SATA) read their new size only when asked; VirtIO
# block disks see it at once.
for disk in $(sysctl -n kern.disks); do
    case "$disk" in
        da[0-9]* | ada[0-9]*) camcontrol reprobe "$disk" > /dev/null 2>&1 || true ;;
    esac
done

service growfs onestart
