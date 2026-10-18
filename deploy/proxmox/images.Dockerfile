# Runs prepare-images.sh in a container, for machines without libguestfs
# (macOS, Windows without WSL). See docs/guides/os-templates.md.
#
#   docker build --platform linux/amd64 -t proxmotion-images -f images.Dockerfile .
#   docker run --rm --platform linux/amd64 -v "$PWD/proxmotion-images:/out" proxmotion-images
FROM debian:13-slim

# virt-customize needs a kernel for its helper VM; linux-image-amd64 provides it.
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl libguestfs-tools linux-image-amd64 \
    && rm -rf /var/lib/apt/lists/*

ENV LIBGUESTFS_BACKEND=direct \
    OUTDIR=/out

COPY prepare-images.sh /usr/local/bin/prepare-images.sh

ENTRYPOINT ["bash", "/usr/local/bin/prepare-images.sh"]
