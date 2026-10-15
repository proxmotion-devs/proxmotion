# Building OS templates

Proxmotion clones new servers from cloud-init VM templates that contain `qemu-guest-agent`.
Building them takes two steps, done by two scripts in `deploy/proxmox/`:

1. **On your own machine** (Linux or WSL, with internet): `prepare-images.sh` downloads the
   official cloud images, verifies their checksums and installs `qemu-guest-agent` into them.
2. **On the Proxmox node** (no internet, no extra packages): `build-templates.sh` turns the
   prepared images into templates with `pvesh` and `qm` only.

Nothing is installed on the hypervisor, and the node never needs internet access.

> **Status:** this procedure has not yet been tested end to end against a real Proxmox VE
> node. Please report problems.

## What you need

- A Linux machine, or Windows with **WSL 2** (Ubuntu or Debian), with internet access and
  a few GB of free disk space.
- `curl` and `libguestfs-tools` on that machine:

  ```bash
  sudo apt update
  sudo apt install curl libguestfs-tools
  ```

- A way to copy files to the node: `scp`, WinSCP, or the Proxmox web UI (see step 3).
- Root shell access on the node (the node's web console **Shell** is enough).

### Notes for WSL

`virt-customize` starts a small helper VM, which needs a Linux kernel in `/boot` and is much
faster with `/dev/kvm`. WSL does not always provide either. If `virt-customize` fails with an
error about the kernel or supermin:

```bash
sudo apt install linux-image-generic
export LIBGUESTFS_BACKEND=direct
```

Without `/dev/kvm` it still works, only slower (a few minutes per image). Run
`libguestfs-test-tool` to diagnose other problems.

## 1. Prepare the images

Get the repository (or just `deploy/proxmox/prepare-images.sh`) on your machine and run:

```bash
cd deploy/proxmox
./prepare-images.sh
```

To prepare only some templates:

```bash
ONLY="ubuntu-24.04 debian-12" ./prepare-images.sh
```

The available keys are listed in `TEMPLATES` at the top of `prepare-images.sh`
(`ubuntu-24.04`, `ubuntu-22.04`, `debian-13`, `debian-12`).

The result lands in `./proxmotion-images/` (change it with `OUTDIR=…`):

```
proxmotion-images/
├── tpl-ubuntu-24.04.qcow2   ← copy these
├── tpl-debian-12.qcow2
├── SHA256SUMS               ← and this
└── downloads/               ← cached original images, not needed on the node
```

Every run prepares the selected images again from the **current** cloud images. Downloads are
cached and fetched again only when a newer image has been published.

## 2. Copy the script and images to the node

The images go into `/root/proxmotion-templates/` on the node.

With `scp` (from your machine):

```bash
ssh root@NODE mkdir -p /root/proxmotion-templates
scp proxmotion-images/tpl-*.qcow2 proxmotion-images/SHA256SUMS root@NODE:/root/proxmotion-templates/
scp build-templates.sh root@NODE:/root/
```

On Windows you can do the same with WinSCP (SFTP, user `root`).

The script must keep Unix (LF) line endings. The repository enforces this, but an editor on
Windows may change it; if bash on the node reports `$'\r': command not found`, run
`sed -i 's/\r$//' /root/build-templates.sh`.

## 3. Alternative: upload through the Proxmox web UI

Recent Proxmox VE releases (8.3 and later, as far as we know) have an **Import** content type
for storages, which lets you upload disk images in the web UI. This has not been verified with
Proxmotion yet:

1. *Datacenter → Storage → `local` → Edit*: add **Import** to the content types.
2. *Node → `local` → Import → Upload*: upload the `tpl-*.qcow2` files and `SHA256SUMS`.
3. The files end up in `/var/lib/vz/import/`, so point the script there in step 4:
   `WORKDIR=/var/lib/vz/import`.

You still need a node shell to run `build-templates.sh` (paste it into an editor in the
web Shell, or upload it with `scp`).

## 4. Build the templates on the node

In a root shell on the node:

```bash
cd /root
chmod +x build-templates.sh
STORAGE=local-lvm POOL=proxmotion ./build-templates.sh
```

- Settings: `STORAGE`, `BRIDGE`, `POOL`, `FIRST_VMID`, `ONLY`, `WORKDIR`, `REFRESH` (see the
  script header). Set `POOL` if the panel's API token is limited to a resource pool.
- Every `tpl-<key>.qcow2` in `WORKDIR` becomes a template named `tpl-<key>-<YYYYMMDD>`
  (e.g. `tpl-ubuntu-24.04-20261016`). With `ONLY`, a missing image is an error.
- The VMID is the first free one from `FIRST_VMID` (default `9000`) upwards, checked across
  the whole cluster. You don't need to pick VMIDs per node.
- Templates are found **by name on this node**. A key that already has a template is skipped,
  so the script is safe to re-run. If a VM that is *not* a template has such a name, the script
  stops instead of guessing.
- The checksum is verified when `SHA256SUMS` was copied along.
- The copied images are kept; delete them yourself once the templates exist.

On a cluster with **local** storage, repeat this on every node that should host servers. On
**shared** storage, running it on one node is enough.

## 5. Use them in the panel

1. *Admin → Clusters*: run a sync so the panel sees the new templates.
2. *Admin → OS templates*: map each Proxmox template to an OS template.

## Refreshing templates

Cloud images receive security updates. To move new servers to a current image:

1. Run `prepare-images.sh` again (step 1) and copy the new images to the node (step 2).
2. On the node: `REFRESH=1 ./build-templates.sh` (optionally with `ONLY=…`). This builds a new
   dated template next to the old one; the old one is not touched.
3. In the panel, sync the cluster and map the OS template to the new Proxmox template.
4. Delete the old template in Proxmox. Servers are full clones, so existing servers do not
   depend on it.

## Why not reuse Virtualizor templates?

Virtualizor's KVM templates are plain disk images and could technically be imported with
`qm importdisk`. We do not recommend it:

- Proxmotion configures the network, root password and SSH keys through **cloud-init**.
  Virtualizor templates are configured by Virtualizor's own mechanism and are not guaranteed
  to contain cloud-init, so servers may boot without network or credentials.
- The App Marketplace and status checks need **qemu-guest-agent**, which may be missing.
- The images are distributed by Virtualizor's vendor; their licence for use outside
  Virtualizor is unclear.

Existing Virtualizor servers do not need templates: they are adopted as they are
(see the migration plan in `AGENTS.md`). Reinstalling them uses Proxmotion's templates.
