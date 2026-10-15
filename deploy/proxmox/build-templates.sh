#!/usr/bin/env bash
# Turns images prepared by prepare-images.sh into Proxmotion VM templates.
#
# Run as root in a Proxmox VE node shell (the panel never does this itself).
# The node needs no internet access and no extra packages: the script only
# runs pvesh and qm. See docs/guides/os-templates.md.
#
# For every $WORKDIR/tpl-<key>.qcow2 it creates a template named
# tpl-<key>-<YYYYMMDD> with a cloud-init drive and `agent: 1`, using the first
# free VMID from FIRST_VMID upwards. Templates are found by name on this node,
# so the script is safe to re-run:
#   - a key that already has a template on this node is skipped;
#   - with REFRESH=1 a new dated template is built next to the old one. Map the
#     new one in the panel, then delete the old template yourself.
#
# Settings (environment variables):
#   STORAGE     storage for disks and the cloud-init drive (default: local-lvm)
#   BRIDGE      bridge for net0 (default: vmbr0)
#   POOL        resource pool of the panel's API token, if one is used (default: none)
#   FIRST_VMID  lowest VMID to use for templates (default: 9000)
#   ONLY        space-separated template keys to build (default: every prepared image)
#   WORKDIR     directory with the prepared images (default: /root/proxmotion-templates)
#   REFRESH     1 = build new templates even if some already exist (default: 0)
#
# Examples:
#   STORAGE=local-lvm POOL=proxmotion ./build-templates.sh
#   REFRESH=1 ONLY="ubuntu-24.04" ./build-templates.sh
set -euo pipefail

STORAGE="${STORAGE:-local-lvm}"
BRIDGE="${BRIDGE:-vmbr0}"
POOL="${POOL:-}"
FIRST_VMID="${FIRST_VMID:-9000}"
ONLY="${ONLY:-}"
WORKDIR="${WORKDIR:-/root/proxmotion-templates}"
REFRESH="${REFRESH:-0}"

NODE="$(hostname -s)"
TODAY="$(date +%Y%m%d)"

log() { printf '==> %s\n' "$*"; }

require_node() {
  if [[ $EUID -ne 0 ]]; then
    echo "Run as root on a Proxmox VE node." >&2
    exit 1
  fi
  command -v qm >/dev/null && command -v pvesh >/dev/null || {
    echo "qm/pvesh not found: this is not a Proxmox VE node." >&2
    exit 1
  }
}

# True when the template key is selected by ONLY (or ONLY is empty).
selected() {
  [[ -z "$ONLY" || " $ONLY " == *" $1 "* ]]
}

# Prints "<vmid> <name> <is template>" for each guest on this node named
# tpl-<key> or tpl-<key>-<YYYYMMDD>. Uses Perl, which every Proxmox VE node has.
find_guests() {
  pvesh get /cluster/resources --type vm --output-format json | perl -MJSON::PP -e '
    my ($node, $base) = @ARGV;
    local $/;
    for my $vm (@{ decode_json(<STDIN>) }) {
      next unless ($vm->{node} // "") eq $node;
      my $name = $vm->{name} // "";
      next unless $name =~ /^\Q$base\E(?:-\d{8})?$/;
      printf "%d %s %d\n", $vm->{vmid}, $name, $vm->{template} ? 1 : 0;
    }' "$NODE" "$1"
}

# Prints the first VMID from FIRST_VMID upwards that is free in the whole cluster.
free_vmid() {
  local vmid="$FIRST_VMID"
  until pvesh get /cluster/nextid --vmid "$vmid" >/dev/null 2>&1; do
    vmid=$((vmid + 1))
  done
  echo "$vmid"
}

# Checks the image against SHA256SUMS from prepare-images.sh, if it was copied along.
verify_image() {
  local file="$1"

  [[ -f "$WORKDIR/SHA256SUMS" ]] || {
    log "No SHA256SUMS in $WORKDIR; skipping the checksum of $file"
    return
  }
  grep -E "[ *]${file}\$" "$WORKDIR/SHA256SUMS" | (cd "$WORKDIR" && sha256sum -c --status -) || {
    echo "Checksum mismatch for $file (or not listed in SHA256SUMS); copy it again." >&2
    exit 1
  }
}

create_template() {
  local vmid="$1" name="$2" image="$3"
  local pool_args=()
  [[ -n "$POOL" ]] && pool_args=(--pool "$POOL")

  qm create "$vmid" --name "$name" "${pool_args[@]}" \
    --ostype l26 --cores 1 --memory 1024 \
    --scsihw virtio-scsi-single \
    --net0 "virtio,bridge=$BRIDGE" \
    --agent enabled=1 \
    --serial0 socket --vga std
  qm set "$vmid" --scsi0 "$STORAGE:0,import-from=$image,discard=on,ssd=1"
  qm set "$vmid" --ide2 "$STORAGE:cloudinit" --boot order=scsi0
  qm template "$vmid"
}

# Builds the template for one image unless it already exists.
build() {
  local key="$1" image="$2"
  local base="tpl-$key" name="tpl-$key-$TODAY" guests vmid

  guests="$(find_guests "$base")"
  if [[ -n "$guests" ]] && awk '$3 == 0 { found = 1 } END { exit !found }' <<<"$guests"; then
    echo "A VM that is not a template uses the name $base on $NODE:" >&2
    echo "$guests" >&2
    echo "Rename it, then run the script again." >&2
    exit 1
  fi
  if awk -v n="$name" '$2 == n { found = 1 } END { exit !found }' <<<"$guests"; then
    log "$name already exists on $NODE; skipping"
    return
  fi
  if [[ -n "$guests" && "$REFRESH" != 1 ]]; then
    log "$base already has a template on $NODE ($(awk '{ print $2 " (VMID " $1 ")" }' <<<"$guests" | paste -sd, -)); skipping, use REFRESH=1 to build a new one"
    return
  fi

  verify_image "${image##*/}"
  vmid="$(free_vmid)"
  log "Creating $name (VMID $vmid) from $image"
  create_template "$vmid" "$name" "$image"
  log "Done: $name (VMID $vmid)"
  BUILT+=("$name (VMID $vmid)")
}

main() {
  require_node

  local wanted
  for wanted in $ONLY; do
    [[ -f "$WORKDIR/tpl-$wanted.qcow2" ]] || {
      echo "Prepared image not found: $WORKDIR/tpl-$wanted.qcow2 (run prepare-images.sh and copy it here)." >&2
      exit 1
    }
  done

  local images=() image key
  shopt -s nullglob
  for image in "$WORKDIR"/tpl-*.qcow2; do
    key="${image##*/tpl-}"
    selected "${key%.qcow2}" && images+=("$image")
  done
  if [[ ${#images[@]} -eq 0 ]]; then
    echo "No prepared images in $WORKDIR (run prepare-images.sh and copy tpl-*.qcow2 here)." >&2
    exit 1
  fi

  BUILT=()
  for image in "${images[@]}"; do
    key="${image##*/tpl-}"
    build "${key%.qcow2}" "$image"
  done

  if [[ ${#BUILT[@]} -eq 0 ]]; then
    log "Nothing to do: every selected template already exists on $NODE."
    return
  fi
  log "Created: ${BUILT[*]}"
  log "Run a cluster sync in the panel, then map the templates under OS templates."
  if [[ "$REFRESH" == 1 ]]; then
    log "After remapping, delete the old templates in Proxmox (servers are full clones and do not depend on them)."
  fi
  log "The images in $WORKDIR are kept; delete them once you no longer need them."
}

main "$@"
