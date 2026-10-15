#!/usr/bin/env bash
# Prepares Proxmotion template images from official cloud images.
#
# Run on a Linux machine or WSL (Ubuntu/Debian) with internet access, not on
# the Proxmox node. For each template it downloads the cloud image, verifies
# the published checksum and installs qemu-guest-agent with virt-customize.
# The result is $OUTDIR/tpl-<key>.qcow2 (e.g. tpl-ubuntu-24.04.qcow2) plus
# SHA256SUMS; copy them to the node and run build-templates.sh there.
#
# Downloads are cached in $OUTDIR/downloads. A cached image is downloaded
# again when it no longer matches the published checksum (a newer image was
# released), so re-running the script always prepares the current images.
# See docs/guides/offline-templates.md.
#
# Requirements: bash, curl, coreutils, virt-customize (apt install libguestfs-tools).
#
# Settings (environment variables):
#   OUTDIR  output directory (default: ./proxmotion-images)
#   ONLY    space-separated template keys to prepare (default: all)
#
# Example:
#   ONLY="ubuntu-24.04 debian-12" ./prepare-images.sh
set -euo pipefail

OUTDIR="${OUTDIR:-$PWD/proxmotion-images}"
ONLY="${ONLY:-}"
DOWNLOADS="$OUTDIR/downloads"

# key|image url|checksum file url|checksum tool
TEMPLATES=(
  "ubuntu-24.04|https://cloud-images.ubuntu.com/noble/current/noble-server-cloudimg-amd64.img|https://cloud-images.ubuntu.com/noble/current/SHA256SUMS|sha256sum"
  "ubuntu-22.04|https://cloud-images.ubuntu.com/jammy/current/jammy-server-cloudimg-amd64.img|https://cloud-images.ubuntu.com/jammy/current/SHA256SUMS|sha256sum"
  "debian-13|https://cloud.debian.org/images/cloud/trixie/latest/debian-13-genericcloud-amd64.qcow2|https://cloud.debian.org/images/cloud/trixie/latest/SHA512SUMS|sha512sum"
  "debian-12|https://cloud.debian.org/images/cloud/bookworm/latest/debian-12-genericcloud-amd64.qcow2|https://cloud.debian.org/images/cloud/bookworm/latest/SHA512SUMS|sha512sum"
)

log() { printf '==> %s\n' "$*"; }

require_tools() {
  local missing=() tool
  for tool in curl sha256sum sha512sum virt-customize; do
    command -v "$tool" >/dev/null || missing+=("$tool")
  done
  if [[ ${#missing[@]} -gt 0 ]]; then
    echo "Missing tools: ${missing[*]} (on Debian/Ubuntu: sudo apt install curl libguestfs-tools)." >&2
    exit 1
  fi
}

# True when the template key is selected by ONLY (or ONLY is empty).
selected() {
  [[ -z "$ONLY" || " $ONLY " == *" $1 "* ]]
}

# True when the cached download matches the published checksum list.
matches_checksum() {
  local file="$1" sums="$2" tool="$3"

  [[ -f "$DOWNLOADS/$file" ]] || return 1
  # Ubuntu lists "hash *file", Debian "hash  file".
  grep -E "[ *]${file}\$" "$sums" | (cd "$DOWNLOADS" && "$tool" -c --status -)
}

# Downloads the image unless the cached copy is still current, then verifies it.
fetch_image() {
  local url="$1" sums_url="$2" tool="$3"
  local file="${url##*/}" sums="$DOWNLOADS/${url##*/}.sums"

  curl -fsSL --retry 3 -o "$sums" "$sums_url"
  if matches_checksum "$file" "$sums" "$tool"; then
    log "Cached $file is current"
    return
  fi

  log "Downloading $file"
  curl -fL --retry 3 -o "$DOWNLOADS/$file.part" "$url"
  mv "$DOWNLOADS/$file.part" "$DOWNLOADS/$file"
  matches_checksum "$file" "$sums" "$tool" || {
    echo "Checksum mismatch for $file; try again later (the mirror may be updating)." >&2
    exit 1
  }
}

main() {
  require_tools
  mkdir -p "$OUTDIR" "$DOWNLOADS"

  local entry key url sums tool file image prepared=0
  for entry in "${TEMPLATES[@]}"; do
    IFS='|' read -r key url sums tool <<<"$entry"
    selected "$key" || continue
    file="${url##*/}"
    image="$OUTDIR/tpl-$key.qcow2"

    fetch_image "$url" "$sums" "$tool"
    log "Customizing tpl-$key"
    cp "$DOWNLOADS/$file" "$image.part"
    virt-customize --format qcow2 -a "$image.part" \
      --install qemu-guest-agent \
      --truncate /etc/machine-id
    mv "$image.part" "$image"
    prepared=$((prepared + 1))
    log "Ready: $image"
  done

  if [[ $prepared -eq 0 ]]; then
    echo "No template matches ONLY=\"$ONLY\"." >&2
    exit 1
  fi

  log "Writing checksums"
  (cd "$OUTDIR" && sha256sum -- tpl-*.qcow2 >SHA256SUMS)

  log "Finished. Copy $OUTDIR/tpl-*.qcow2 and SHA256SUMS to /root/proxmotion-templates/ on the node,"
  log "then run build-templates.sh there."
}

main "$@"
