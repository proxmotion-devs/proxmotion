# Proxmotion: extend the system partition (C:) to the size of its disk after
# an upgrade (docs/adr/0010 in the Proxmotion repository). Installed and run
# by Proxmotion through the QEMU guest agent; changes to this file are
# overwritten. An OS template can replace it in the admin panel.
#
# C:\ProgramData\Proxmotion\disk.json (written by Proxmotion):
#   { "disk_mib": 40960 }
#
# A recovery partition behind C: blocks the growth: build templates without
# one, or replace this script. Safe to run any number of times.
$ErrorActionPreference = 'Stop'

Update-HostStorageCache

$partition = Get-Partition -DriveLetter C
$supported = Get-PartitionSupportedSize -DiskNumber $partition.DiskNumber -PartitionNumber $partition.PartitionNumber

if ($supported.SizeMax -gt $partition.Size + 1MB) {
    Resize-Partition -DiskNumber $partition.DiskNumber -PartitionNumber $partition.PartitionNumber -Size $supported.SizeMax
}
