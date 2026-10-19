# Building a template from an installer ISO (by hand, in Proxmox)

This guide shows how to turn an OS installer ISO into a Proxmox VM template that Proxmotion can
clone. Everything happens in the Proxmox web UI and in the VM's console; no scripts and no
extra tools are needed.

Use it when there is no official cloud image for the OS you want, or when you want full
control over the installation. For Ubuntu and Debian, the cloud-image path in
[os-templates.md](os-templates.md) is faster.

> **Status:** these steps follow the documented behaviour of Proxmox VE, cloud-init and the
> installers, but have not yet been tested end to end with Proxmotion. Please report problems.

**Contents**

1. [What a Proxmotion template needs](#what-a-proxmotion-template-needs)
2. [Upload the ISO](#1-upload-the-iso)
3. [Create the VM](#2-create-the-vm)
4. [Install the OS](#3-install-the-os): [Ubuntu](#ubuntu-server) · [Debian](#debian) · [AlmaLinux / Rocky / RHEL](#almalinux--rocky--rhel)
5. [Prepare the system for cloning](#4-prepare-the-system-for-cloning)
6. [Turn the VM into a template](#5-turn-the-vm-into-a-template)
7. [Use it in the panel](#6-use-it-in-the-panel)
8. [Windows Server](#windows-server)
9. [Updating a template](#updating-a-template)

## What a Proxmotion template needs

When the panel creates a server it makes a full clone of the template, sets CPU, memory and
network, grows the disk, and passes the hostname, root password and SSH keys through the
Proxmox **cloud-init drive** (with `ciuser=root`). For that to work, the template must have:

| Requirement | Why |
|---|---|
| `cloud-init` installed and enabled, with its network configuration **not** disabled | Sets the IP addresses, hostname, root password and SSH keys |
| `qemu-guest-agent` installed, and *QEMU Guest Agent* enabled on the VM | Status checks, password reset, App Marketplace |
| One root partition at the **end** of the disk, no swap partition, no LVM | cloud-init grows the last partition and its file system on first boot |
| Root login allowed (see step 4) | The panel gives the customer a root password and SSH keys for `root` |
| No machine ID, SSH host keys or installer network files | Every clone must get its own identity and the network from cloud-init |
| A cloud-init drive, the disk first in the boot order, no ISO attached | Proxmox writes the cloud-init data to that drive |

## 1. Upload the ISO

*Node → `local` (or another storage with the **ISO image** content type) → ISO Images*:

- **Upload** an ISO from your computer, or
- **Download from URL** if the node has internet access. Paste the vendor's checksum into the
  checksum fields so Proxmox verifies the file.

## 2. Create the VM

*Create VM* (top right). Use a VMID outside the range your servers use (e.g. 9000+) and a name
like `tpl-almalinux-9-20261016`.

| Tab | Setting |
|---|---|
| General | Name as above. *Resource Pool*: the panel's pool, if the panel's API token is limited to one |
| OS | The uploaded ISO; *Type* Linux, *Version* 6.x – 2.6 Kernel |
| System | *Graphic card* Default, *Machine* Default (i440fx), *BIOS* Default (SeaBIOS), *SCSI Controller* VirtIO SCSI single, **QEMU Agent: on** |
| Disks | Bus **SCSI**, size **10 GiB** (keep it small; the panel grows the disk per package), *Discard* on, *SSD emulation* on, on the storage servers will use |
| CPU | 1 socket, 2 cores, *Type* default (`x86-64-v2-AES`); avoid `host` so clones run on every node |
| Memory | 2048 MiB (only for the installation; the panel sets the real value) |
| Network | *Bridge* one with internet access for the installation, *Model* VirtIO |

Do not start it yet if the installer needs a network setting you have not decided; otherwise
tick *Start after created*.

## 3. Install the OS

Open the VM's **Console** and install a minimal system. The installers all require the same
choices: **one root partition without swap and without LVM**, OpenSSH server, nothing else.

### Ubuntu Server

1. Choose *Ubuntu Server (minimized)* or *Ubuntu Server*.
2. Network: DHCP or a temporary static address; it is removed later.
3. Storage: *Use an entire disk* and **untick *Set up this disk as an LVM group***. Ubuntu
   uses a swap file, not a partition, so the layout is already right.
4. Profile: create a temporary user (e.g. `setup`); it is removed in step 4.
5. Tick **Install OpenSSH server**. Skip the featured snaps.
6. Reboot when asked (in *Hardware*, set the CD/DVD drive to *Do not use any media* if the VM
   boots into the installer again).

### Debian

1. *Install* (text mode is fine). Set a **root password**; create the required normal user
   (e.g. `setup`); it is removed in step 4.
2. Partitioning: *Manual*. Create **one primary partition** over the whole disk, ext4, mount
   point `/`, bootable flag on. Do **not** create swap; confirm the warning about missing swap.
3. Software selection: only **SSH server** and **standard system utilities**.
4. Install GRUB to the disk (`/dev/sda`). Reboot.

### AlmaLinux / Rocky / RHEL

1. *Installation Destination*: select the disk, *Storage Configuration: Custom* → *Done*.
   Choose **Standard Partition**, then add one partition with mount point `/` and file system
   xfs using all the space. On a SeaBIOS VM the installer may ask for a 1 MiB **biosboot**
   partition; create it first so `/` stays last. No swap.
2. *Software Selection*: **Minimal Install**.
3. *Root Password*: set one and tick **Allow root SSH login with password**. No extra user is
   needed.
4. *Network & Host Name*: switch the interface on (DHCP or temporary static).
5. *Begin Installation*, then reboot.

## 4. Prepare the system for cloning

Log in on the console as root (Ubuntu: log in as `setup`, then `sudo -i`). Run the block for
your OS, then the common block.

**Ubuntu / Debian:**

```bash
apt update && apt full-upgrade -y
apt install -y cloud-init cloud-guest-utils qemu-guest-agent openssh-server
systemctl start qemu-guest-agent

# Network: let cloud-init own it
rm -f /etc/netplan/*.yaml                                      # Ubuntu
rm -f /etc/cloud/cloud.cfg.d/subiquity-disable-cloudinit-networking.cfg \
      /etc/cloud/cloud.cfg.d/99-installer.cfg \
      /etc/cloud/cloud.cfg.d/90-installer-network.cfg \
      /etc/cloud/cloud-init.disabled                           # Ubuntu installer leftovers
printf 'auto lo\niface lo inet loopback\n\nsource /etc/network/interfaces.d/*\n' \
  > /etc/network/interfaces                                    # Debian only
apt clean
```

**AlmaLinux / Rocky / RHEL:**

```bash
dnf upgrade -y
dnf install -y cloud-init cloud-utils-growpart qemu-guest-agent
# The agent blocks guest-exec and file access here by default; the panel needs them
sed -i -e 's/^FILTER_RPC_ARGS=.*/FILTER_RPC_ARGS=""/' -e 's/^BLACKLIST_RPC=.*/BLACKLIST_RPC=/' \
  /etc/sysconfig/qemu-ga
# SELinux confines the agent's commands (no directories under /var/lib, no installers):
# make only the agent's domain permissive, the rest of SELinux stays enforcing
dnf install -y policycoreutils-python-utils
semanage permissive -a virt_qemu_ga_t
systemctl enable qemu-guest-agent cloud-init cloud-init-local cloud-config cloud-final
rm -f /etc/NetworkManager/system-connections/*                 # installer network profile
dnf clean all
```

**All Linux:** allow root login for the panel, then remove everything that must be unique per
server.

```bash
# cloud-init: read the Proxmox drive, keep root login and password authentication
cat > /etc/cloud/cloud.cfg.d/99-proxmotion.cfg <<'EOF'
datasource_list: [NoCloud, ConfigDrive, None]
disable_root: false
ssh_pwauth: true
EOF

# SSH: allow root to log in (with password and keys)
mkdir -p /etc/ssh/sshd_config.d
printf 'PermitRootLogin yes\nPasswordAuthentication yes\n' > /etc/ssh/sshd_config.d/99-proxmotion.conf

# Unique identity: regenerated on the clone's first boot
cloud-init clean --logs --seed
truncate -s 0 /etc/machine-id
rm -f /var/lib/dbus/machine-id && ln -s /etc/machine-id /var/lib/dbus/machine-id 2>/dev/null || true
rm -f /etc/ssh/ssh_host_*
```

> Root login with a password is what most VPS customers expect, and it matches how the panel
> hands out credentials. If you only want key-based logins, use `PermitRootLogin
> prohibit-password`, `PasswordAuthentication no` and `ssh_pwauth: false` instead; customers
> then need an SSH key.

**Remove the temporary user** (Ubuntu and Debian). It cannot be removed while you are logged in
as it, so on Ubuntu first give root a password, log out, and log in again as root:

```bash
passwd root          # Ubuntu only: temporary, cloud-init replaces it on every clone
exit                 # leave sudo, then log out and log in as root on the console
```

As root:

```bash
userdel -r setup                  # Ubuntu and Debian
passwd -l root                    # no installer password in the template; cloud-init sets the real one
history -c && rm -f /root/.bash_history
poweroff
```

## 5. Turn the VM into a template

With the VM stopped, in the Proxmox web UI:

1. *Hardware → CD/DVD Drive*: **Remove** it (or at least set *Do not use any media*).
2. *Hardware → Add → CloudInit Drive*: bus IDE or SCSI, on the same storage as the disk.
3. Leave the *Cloud-Init* tab empty: the panel fills it in for every server.
4. *Options*: *Boot Order* only the disk (`scsi0`); *QEMU Guest Agent* enabled.
5. *More → Convert to template*.

Optional but recommended: *Hardware → Add → Serial Port* (`serial0`, socket) for a serial
console later.

On a cluster with **local** storage, create the template on every node that should host servers
(or clone it there with *Clone → Target node*, mode *Full clone*, then convert the clone). With
**shared** storage, one template is enough.

## 6. Use it in the panel

1. If the panel's API token is limited to a resource pool, make sure the template is in that
   pool (*Datacenter → Permissions → Pools → `<pool>` → Members → Add → Virtual Machine*).
2. *Admin → Clusters*: run a sync.
3. *Admin → OS templates*: map the new Proxmox template to an OS template. For a system built on
   another one (Pardus on Debian, Linux Mint on Ubuntu, Oracle Linux on AlmaLinux), tick *Based on
   another system*, enter its `ID` from `/etc/os-release` and its version, and choose the base system
   and base version (Pardus 23: Debian 12): apps are offered by the base (ADR 0022). Without a known
   base, choose `other`: the template works but no app is offered.
4. Create a test server and check: it boots with the right IP and hostname, the root password
   and SSH keys work, the disk has the package size (`df -h /`), and the server page shows the
   guest agent as reachable.

## Windows Server

Choose the family **windows** for the OS template in the panel. How the panel handles Windows
servers, and a Cloudbase-Init configuration that avoids an extra restart on the first boot, are in
[windows-templates.md](windows-templates.md).

You need two ISOs on the storage: the **Windows Server** ISO and the **VirtIO drivers** ISO
(`virtio-win.iso`, stable release from the Fedora project:
`https://fedorapeople.org/groups/virt/virtio-win/direct-downloads/stable-virtio/virtio-win.iso`).

**Create the VM** as in step 2, with these differences:

| Tab | Setting |
|---|---|
| OS | Windows Server ISO; *Type* Microsoft Windows, the matching version. Tick **Add additional drive for VirtIO drivers** and choose `virtio-win.iso` |
| System | *Machine* q35, *BIOS* **OVMF (UEFI)**, *Add EFI Disk* on (pre-enrolled keys), *SCSI Controller* VirtIO SCSI single, **QEMU Agent: on**. A TPM is not needed for Windows Server |
| Disks | SCSI, **40 GiB** or more, *Discard* and *SSD emulation* on |
| CPU / Memory | 2 cores, 4096 MiB |
| Network | VirtIO |

**Install:**

1. Press a key when the console shows *Press any key to boot from CD or DVD*.
2. Choose the *Desktop Experience* or *Core* edition you are licensed for.
3. At *Where do you want to install Windows?* no disk is listed: *Load driver* → browse the
   VirtIO CD → `vioscsi\<your Windows version>\amd64` → install. The disk appears; install to
   it (Windows creates its own partitions; that is fine).
4. Set a temporary Administrator password and log in.

**Prepare:**

1. On the VirtIO CD run **`virtio-win-guest-tools.exe`**: it installs all VirtIO drivers and
   the QEMU guest agent.
2. Install Windows updates.
3. Download and install **Cloudbase-Init** (the Windows counterpart of cloud-init, from
   `https://cloudbase.it/cloudbase-init/`). In the installer: user name `Administrator`, tick
   *Run Cloudbase-Init service as LocalSystem*.
4. Before finishing, edit both
   `C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\cloudbase-init.conf` and
   `cloudbase-init-unattend.conf` so they contain:

   ```ini
   metadata_services=cloudbaseinit.metadata.services.configdrive.ConfigDriveService
   ```

   See also the *Cloud-Init on Windows* section of the Proxmox VE documentation, and the
   recommended configuration of both files in [windows-templates.md](windows-templates.md#recommended-configuration).
5. On the last installer page tick **Run Sysprep to create a generalized image** and
   **Shutdown when Sysprep terminates**, then *Finish*. The VM generalizes and powers off. Do
   not start it again.

**Turn it into a template** as in step 5: remove **both** CD drives, add a CloudInit drive,
boot order only the disk, *Convert to template*.

Licensing and activation (SPLA, AVMA, KMS) are your responsibility; the template contains no
product key unless you add one.

## Updating a template

A template should be refreshed now and then for security updates:

1. *Clone* the template (mode **Full clone**). In the clone's *Cloud-Init* tab set *User*
   `root` and a temporary *Password* (Windows: just start it), start the clone and log in on
   the console.
2. Install updates, then repeat the **All Linux** block of step 4 and the final block
   (`passwd -l root`, history, `poweroff`). In *Cloud-Init*, clear the user and password again. For Windows, run Sysprep again (step *Prepare* 5, using the
   `Unattend.xml` that Cloudbase-Init installed:
   `C:\Windows\System32\Sysprep\sysprep.exe /generalize /oobe /shutdown /unattend:"C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\Unattend.xml"`).
3. Convert the clone to a template with a new dated name, sync the panel and map the OS template
   to it.
4. Delete the old template. Servers are full clones and do not depend on it.
