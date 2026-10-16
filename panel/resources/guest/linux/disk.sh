#!/bin/sh
# Proxmotion: grow the root file system to the size of its disk after an
# upgrade (docs/adr/0010 in the Proxmotion repository). Installed and run by
# Proxmotion through the QEMU guest agent; changes to this file are
# overwritten. An OS template can replace it in the admin panel.
#
# /etc/proxmotion/disk.vars (written by Proxmotion):
#   disk_mib=20480                 the new size of the disk
#
# Handles a root file system on a partition (ext2/3/4, XFS, Btrfs), on the
# whole disk, or on LVM whose physical volumes are partitions of that disk.
# Needs growpart (cloud-guest-utils / cloud-utils-growpart). Safe to run any
# number of times.
set -eu

grow_partition() {
    part=$1
    name=$(basename "$(readlink -f "$part")")

    if [ ! -f "/sys/class/block/$name/partition" ]; then
        return 0
    fi

    if ! command -v growpart > /dev/null 2>&1; then
        echo "growpart is not installed (cloud-guest-utils or cloud-utils-growpart)." >&2
        exit 3
    fi

    disk=/dev/$(lsblk -n -d -o PKNAME "$part" | head -n 1)
    number=$(cat "/sys/class/block/$name/partition")

    # Exit code 1 means the partition already fills the disk.
    growpart "$disk" "$number" || [ $? -eq 1 ]
}

grow_filesystem() {
    case "$2" in
        ext2 | ext3 | ext4) resize2fs "$1" ;;
        xfs) xfs_growfs / ;;
        btrfs) btrfs filesystem resize max / ;;
        *)
            echo "Unsupported root file system $2: grow it by hand." >&2
            exit 4
            ;;
    esac
}

source=$(findmnt -n -o SOURCE /)
fstype=$(findmnt -n -o FSTYPE /)
type=$(lsblk -n -d -o TYPE "$source" 2> /dev/null | head -n 1 || true)

case "$type" in
    lvm)
        vg=$(lvs --noheadings -o vg_name "$source" | tr -d ' ')

        for pv in $(pvs --noheadings -o pv_name -S "vg_name=$vg" | tr -d ' '); do
            grow_partition "$pv"
            pvresize "$pv"
        done

        if [ "$(vgs --noheadings -o vg_free_count "$vg" | tr -d ' ')" -gt 0 ]; then
            lvextend -l +100%FREE "$source"
        fi

        grow_filesystem "$source" "$fstype"
        ;;
    part)
        grow_partition "$source"
        grow_filesystem "$source" "$fstype"
        ;;
    disk)
        grow_filesystem "$source" "$fstype"
        ;;
    *)
        echo "Could not find the device of the root file system ($source): grow it by hand." >&2
        exit 2
        ;;
esac
