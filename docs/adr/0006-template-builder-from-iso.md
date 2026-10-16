# 6. Building OS templates from installer ISOs in the panel

- **Status:** Rejected (2026-10-16): the maintainers do not need a template builder in the panel. Templates are built by hand from ISOs ([iso-templates.md](../guides/iso-templates.md)) or from cloud images ([os-templates.md](../guides/os-templates.md)).
- **Date:** 2026-10-16

## Context

Today templates come from official cloud images, prepared off-node with `virt-customize`
(`deploy/proxmox/`, docs/guides/os-templates.md). That covers Ubuntu and Debian only, needs a
manual Linux/WSL step, and cannot produce Windows templates at all.

Providers want to build templates from **installer ISOs** — Ubuntu, Debian, the RHEL family
(AlmaLinux, Rocky, CentOS Stream) and **Windows Server** — from the admin panel, without a
shell on the node and without SSH (AGENTS.md 3.3/8).

Every OS installer can run unattended when it finds an answer file, but each finds it
differently:

| Family | Answer file | How the installer finds it |
|---|---|---|
| Ubuntu (22.04+) | autoinstall `user-data` | A volume labelled `cidata`; still asks for confirmation unless `autoinstall` is on the kernel command line |
| Debian | preseed | Only via a kernel command line parameter (`preseed/file=…` / `auto url=…`) |
| RHEL family | kickstart | Loaded automatically from a volume labelled `OEMDRV` |
| Windows Server | `autounattend.xml` | Loaded automatically from the root of any attached drive |

Proxmox cannot set kernel parameters for a VM booting from an ISO, so Ubuntu and Debian need a
**remastered ISO** whose boot menu contains the parameters.

## Decision

### Flow

An admin opens *Admin → OS templates → Build from ISO*, picks a cluster/node, storage, OS
family, an ISO source and a few options. The panel then runs a **`BuildTemplate` pipeline**
in the queue (idempotent steps, recorded on a `Task`, AGENTS.md 3.3/3–4):

1. **Get the ISO**
   - *URL* (with an optional published checksum): the **panel** downloads it to its own
     storage, verifies the checksum, and uploads it with `POST /nodes/{node}/storage/{storage}/upload`
     (`content=iso`).
   - *Existing ISO* already on a Proxmox storage (typical for Windows, whose ISOs cannot be
     downloaded anonymously): used as is.
   - *Upload* from the admin's browser to the panel, then as for URL.
   We do not use `download-url`: as far as we know it requires `Sys.Modify` on the node, which
   the permission checker treats as a dangerous privilege. **To verify.**
2. **Remaster** (Ubuntu, Debian only): the panel adds the boot parameters to the ISO's GRUB
   and isolinux configuration with `xorriso`, keeping the original boot records, and uploads
   the result as `proxmotion-<build uuid>.iso`. This needs the ISO on the panel, so the
   "existing ISO" source is not offered for these families.
3. **Answer ISO**: the panel renders the answer file from a bundled, versioned template per
   family (`resources/template-builder/<family>/`), writes it to a small ISO with the right
   volume label (`cidata`, `OEMDRV`, or any label for Windows) and uploads it.
4. **Install VM**: created in the panel's resource pool with the target disk, the installer
   ISO, the answer ISO and — for Windows — the **virtio-win** ISO. A temporary random root /
   Administrator password is generated and stored encrypted for the duration of the build.
5. **Install**: the VM is started; the answer file ends the installation with a **power-off**.
   The panel polls `status/current` until the VM is stopped, with a per-family timeout
   (default 60 minutes Linux, 120 minutes Windows). The admin can watch progress in the
   existing console (ADR 0002).
6. **Finalize**: detach and delete the ISO drives and the answer ISO, add the cloud-init
   drive, set `agent: 1`, `ostype`, `scsihw`, then `POST /nodes/{node}/qemu/{vmid}/template`.
   Temporary ISOs (remastered and answer) are deleted from storage.
7. **Register**: the template appears in the next sync; the build offers "map to OS template"
   directly.

On failure the VM is kept (stopped) for inspection and the build can be retried or discarded;
discarding deletes the VM and the temporary ISOs.

### What the answer files must produce

Every template, whatever the family, ends up with:

- **cloud-init** (Linux) or **cloudbase-init** (Windows) enabled, reading the Proxmox cloud-init
  drive (NoCloud for Linux, ConfigDrive for Windows).
- **qemu-guest-agent** installed and enabled (Windows: from virtio-win, together with the virtio
  storage/network drivers).
- No SSH host keys, an empty `machine-id` (Linux) / **sysprep `/generalize /oobe /shutdown`**
  (Windows), and the temporary password locked or removed.
- A single root partition that cloud-init / cloudbase-init grows on first boot (no swap
  partition; disk is grown by the provisioning pipeline).

### Network during the build

Installers default to DHCP, which many hosting networks do not have. The admin chooses:

- **No network**: only works with full/offline ISOs (Debian DVD, RHEL DVD, Windows). The
  required packages (cloud-init, qemu-guest-agent) must be on the ISO. **To verify per ISO.**
- **Temporary IP**: the panel reserves an address from a chosen IP pool through `IpAllocator`
  for the build and writes a static configuration into the answer file; the address is freed
  when the build ends.

### Windows specifics

- The admin provides the Windows Server ISO and the **virtio-win** ISO (both as "existing ISO"
  or upload); the panel does not download Microsoft media.
- The cloudbase-init MSI is downloaded by the panel and placed on the answer ISO (no internet
  needed in the VM).
- Product keys are optional build inputs, stored encrypted. **Activation and licensing are out
  of scope**: the provider is responsible for valid licences (SPLA, AVMA, KMS).
- BIOS vs. UEFI: SeaBIOS avoids the "press any key to boot from CD" prompt of the UEFI boot
  loader on an empty disk. Which Windows Server versions install on SeaBIOS must be verified;
  UEFI may need a remastered `efisys_noprompt` boot image.

### Data and code

- New model **`TemplateBuild`** (cluster, node, storage, family, ISO source, options, status,
  resulting VMID, encrypted temporary credentials), statuses
  `queued → preparing → installing → finalizing → completed`, `* → failed`, `failed → discarded`.
- `app/Domain/TemplateBuilder/` (answer file rendering, ISO building), `Provisioning/Pipelines/BuildTemplatePipeline`,
  new Proxmox resource methods for `storage/upload`, `storage/content` delete and `qemu/{vmid}/template`.
- Answer file templates are rendered with escaping per format (YAML, preseed, kickstart, XML);
  admin inputs (hostname, locale, timezone, keyboard, product key) are validated against
  allow-lists and never interpolated unescaped (AGENTS.md 7.7).
- **New panel dependency: `xorriso`** (GPL-3.0, called as an external program, which is
  compatible with AGPL-3.0) in the Docker image and the native installer.
- **Token privileges:** adds `Datastore.AllocateTemplate` on the ISO storage; the permission
  checker lists it.
- Every build, retry and discard is written to `AuditLog`.

## Consequences

- One flow, from the admin panel, for all four families, also on nodes without internet (the
  panel downloads ISOs). No shell on the node and no SSH.
- The cloud-image scripts in `deploy/proxmox/` stay as the quick path for Ubuntu/Debian.
- The panel needs temporary disk space for ISOs (several GB per concurrent build); builds run
  one at a time per node.
- Answer files and remastering differ between OS releases. Each supported OS version gets its
  own tested answer file; adding a release means adding and testing one.
- Large uploads to Proxmox go through the panel's HTTP client (multipart, streamed); upload
  limits and timeouts must be checked with real ISO sizes.
- **Not yet verified** (to be checked against the API viewer and a test Proxmox before
  implementation): `upload` with `content=iso` through an API token and its privileges,
  `download-url` privileges, Ubuntu autoinstall confirmation behaviour, offline package
  availability on each ISO, Windows Server on SeaBIOS, cloudbase-init with the Proxmox
  ConfigDrive.
- Placement in the roadmap: an admin feature of its own (**Phase 3b**, after the
  client panel and before billing), since templates are needed before going live.
