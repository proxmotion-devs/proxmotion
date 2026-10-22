# 27. Linked clones per OS template

- **Status:** Accepted (trial: the maintainers are comparing both modes)
- **Date:** 2026-10-20

## Context

Every server is a full clone of its Proxmox template (Phase 2): `POST .../clone` with `full=1`, onto the storage placement chose. A full clone copies the whole disk. Measured on 2026-10-20 (Windows Server 2022, 2 vCPU, LVM-thin to LVM-thin on one node): the full clone took 2:00, Windows setup with one restart another 2:00, and the panel reported the server *Active* about 2:17 after the clone ended. The copy is about half of the time until a Windows server is ready; Linux cloud images are smaller, but the copy still dominates on slow storage.

A **linked clone** shares the template's base volume and only stores what the guest writes afterwards (a thin snapshot on LVM-thin, a ZFS clone, an RBD clone, a qcow2 overlay). It takes seconds, but it ties the server to its template. The maintainers have not decided which they want, so both must be possible and easy to switch per template.

## What Proxmox says

From the API viewer (`POST /nodes/{node}/qemu/{vmid}/clone`, read 2026-10-20 from `apidoc.js`):

- `full`: "Create a full copy of all disks. This is always done when you clone a normal VM. For VM templates, we try to create a linked clone by default." So linked clones exist **only from templates**, and `full=0` asks for one.
- `storage`: "Target storage for full clone." It is documented for full clones only. A linked clone stays on the storage the template's disks are on. qemu-server refuses `storage` together with a linked clone (`parameter 'storage' not allowed for linked clones`, from memory of the qemu-server source: **unverified** on a real node), so the panel never sends it with `full=0`.
- `format`: "Only valid for full clone."
- `target`: "Target node. Only allowed if the original VM is on shared storage." On local storage a clone (full or linked) can only be made on the template's own node.
- Permissions are the same as for a full clone (`VM.Clone` on the template, `VM.Allocate` on the new VM or its pool, `Datastore.AllocateSpace` on the storage). The permission check needs no change.

Storage types whose volumes can be the base of a linked clone, from the Proxmox storage documentation: thin LVM (`lvmthin`), ZFS (`zfspool`), Ceph RBD (`rbd`), and qcow2 (or vmdk) images on file storages (`dir`, `nfs`, `cifs`). Plain LVM, iSCSI and raw images on file storages cannot. The panel's list is exactly this (vmdk left out); ZFS over iSCSI, GlusterFS and others are treated as unsupported until someone verifies them. Whether an `efidisk0`/`tpmstate0` volume of a Windows template is linked like the other disks is **unverified**.

## Decision

### 1. One setting per OS template

`os_templates.clone_mode`: `full` (default; every existing template keeps it) or `linked`, enum `CloneMode`. It is edited on the OS template form (*Clone method*, with a short explanation of when a linked clone is made) and shown as a "Linked clones" badge in the list. A change is audited as `os_template.clone_mode_changed` with the old and new value. It applies to servers created or reinstalled afterwards; existing servers stay what they are.

### 2. When a linked clone is made

`CreateServerPipeline::cloneVm()` (also used by reinstall) picks the Proxmox template as before (the one on the server's node first). In `linked` mode it reads the template's config and asks `LinkedClones::unavailableReason()`. A linked clone (`full=0`, no `storage`) is made only when all of these hold:

1. The template has disks (CD-ROM and cloud-init drives left out), all on **one** storage.
2. The server's chosen storage, when it has one, **is** that storage (Proxmox cannot put a linked clone anywhere else). Without a chosen storage (some adopted servers being reinstalled) the template's storage is used.
3. The panel knows that storage on the server's node and it is available.
4. The template is on the server's node, or that storage is **shared** (then `target` is sent, as for full clones).
5. The storage type is `lvmthin`, `zfspool` or `rbd`, or `dir`/`nfs`/`cifs` with every disk a `.qcow2` image.

### 3. Fallback to a full clone

If any rule fails, or Proxmox refuses the linked clone request itself (an HTTP error before a task starts), the pipeline makes a full clone exactly as in `full` mode. The clone step's message says what happened, so administrators can see it on the task:

- `Linked clone of template 9000.`
- `Full clone: The server's storage local-lvm is not the template's storage fast-ssd.` (or another rule's reason, or `Proxmox refused a linked clone: …`)

A clone **task** that starts and then fails is not retried as a full clone: Proxmox may have created part of the VM, and the usual retry of the create or reinstall task takes over (the clone step sees the VM and continues). In `full` mode nothing changes, not even the request.

The panel does not steer placement towards the template's storage. On a node with one VM storage this does not matter; with several, placement may choose another storage and the server falls back to a full clone, with the reason on the step. Making placement prefer the template's storage for linked templates is a follow-up.

### 4. Risks and how the panel handles them

| Risk | Handling |
|---|---|
| Servers depend on the template: Proxmox refuses to delete a base volume that linked clones use, so old templates pile up after updates. | Documented in `docs/guides/iso-templates.md` (*Updating a template*) and *Help*: keep the old template until its linked clones are reinstalled or terminated. The panel never deletes templates. Follow-up: show per Proxmox template how many servers are linked clones of it. |
| A linked clone needs the template's storage; on local LVM-thin every node needs its own copy of the template. | Rule 2.4 and the fallback; the guide already says to create the template on every node with local storage. |
| Thin pool data or metadata fills up, and a full pool hurts every VM in it, not only the new one. | Unchanged from today's thin pools, but linked clones make over-provisioning easier because they start almost empty. The panel's capacity checks count the server's full disk size as before (no credit for sharing). Follow-up: sync and show thin pool data and metadata fill levels (`/nodes/{node}/storage/{storage}/status` gives only data usage; metadata needs `lvs` and is not in the API; **unverified**). |
| Migrate or move disk turns a linked disk into a full copy. | Nothing to do: the result is a normal server. Live migration is not offered yet (Phase 9). |
| The space saving shrinks as the guest writes (updates, logs, data). | Expected; the gain is mainly creation time. Capacity planning keeps using full sizes. |
| dir/NFS with raw images, plain LVM, iSCSI are not supported. | Rule 2.5 and the fallback. |
| Reinstall: the old VM is destroyed and recloned. | Same rules; a reinstall onto a linked template makes a linked clone, the MAC address is kept as before. |
| Growing the disk of a linked clone. | `PUT .../resize` works on the clone's own volume as for any VM (**unverified** on real storage; tested with the fake only). |

### 5. Not verified on a real Proxmox

Everything above was tested with `FakeProxmoxCluster` only (`LinkedCloneTest`, `ReinstallTest`). To check on a test node: `full=0` without `storage` on LVM-thin, ZFS and a qcow2 directory storage; that `storage` with `full=0` is refused as assumed; linked clone across nodes on Ceph with `target`; `efidisk0`/`tpmstate0` of a Windows template; resize of a linked disk; deleting a template with linked clones failing; the time saved on the Windows template of the measurement above.

## Consequences

- Administrators can switch one template to linked clones, compare creation times on the task page, and switch back at any time; nothing else changes for full templates.
- Templates with linked clones cannot be removed until their servers are gone, which administrators must plan for.
- Follow-ups: placement preferring the template's storage; per-template count of servers that are linked clones (would need recording the clone method on the server); thin pool fill levels.
