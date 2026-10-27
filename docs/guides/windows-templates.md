# Windows templates

*Türkçe: [tr/windows-templates.md](tr/windows-templates.md)*

This guide explains how a Windows Server template for Proxmotion works and how to build one, in
two ways: **with Sysprep** (Microsoft's supported way) and **without Sysprep** (faster, with known
costs). It explains why each step is there, so you can adapt it to another Windows version or your
own image, and how to check the result.

Linux templates have their own guide: [iso-templates.md](iso-templates.md).

**Contents**

1. [How a Windows server is born from a template](#how-a-windows-server-is-born-from-a-template)
2. [Making clones unique: with or without Sysprep](#making-clones-unique-with-or-without-sysprep)
3. [Build the base system](#build-the-base-system) (both ways): [ISOs](#1-isos) · [VM](#2-create-the-vm) · [Install](#3-install-windows) · [Prepare](#4-prepare-windows) · [Cloudbase-Init](#5-install-cloudbase-init)
4. [Way A: with Sysprep](#way-a-with-sysprep)
5. [Way B: without Sysprep](#way-b-without-sysprep)
6. [Turn the VM into a template](#turn-the-vm-into-a-template) (both ways)
7. [Add it to the panel and test it](#add-it-to-the-panel-and-test-it)
8. [Configuration files explained](#configuration-files-explained)
9. [How fast a new server can be ready](#how-fast-a-new-server-can-be-ready)
10. [Updating a template](#updating-a-template)
11. [What the panel does for Windows](#what-the-panel-does-for-windows)
12. [Troubleshooting](#troubleshooting)
13. [Not verified yet](#not-verified-yet)

## How a Windows server is born from a template

When the panel creates a server, it clones the template, sets cores, memory, the network card and
the cloud-init fields (password, addresses, DNS, VM name), grows the disk and starts the VM. For a
VM whose OS type is **Microsoft Windows**, Proxmox writes the cloud-init fields as a *config drive*
(a small CD labelled `config-2`):

| File on the CD | Content | Read by |
|---|---|---|
| `meta_data.json` | `uuid` (the *instance ID*), `admin_pass`, SSH keys | Cloudbase-Init: password |
| `network_data.json` | addresses, gateway, DNS | Cloudbase-Init: network |
| `user_data` | `#cloud-config` with `hostname: …` | Cloudbase-Init's `UserDataPlugin`: computer name |

Only for a Windows OS type does Proxmox put the password there in plain text; for other types it
stores a hash, which Windows cannot use.

**Cloudbase-Init** is the Windows counterpart of cloud-init. Its *plugins* each do one job, and it
remembers in the registry, per instance ID, which plugins already ran. A clone has a new instance
ID, so every plugin runs once on it; on later boots they are skipped.

The panel then waits until Windows is ready (see [What the panel does](#what-the-panel-does-for-windows)):
the guest agent answers, Windows setup reports `IMAGE_STATE_COMPLETE`, and the `cloudbase-init`
service has stopped. Then it runs its own scripts (additional addresses) through the guest agent.

So a Windows template needs: the **VirtIO drivers** (disk and network), the **QEMU guest agent**,
**Cloudbase-Init** configured to read the config drive, **Remote Desktop** on, and a way to make
every clone a **different machine**, which is where the two ways differ.

## Making clones unique: with or without Sysprep

A Windows installation carries values that should differ on every machine. Sysprep's *generalize*
pass removes them; the first boot of each clone then runs Windows setup's *specialize* pass and
OOBE, which create new ones. Without Sysprep the clone starts as an exact copy, and you remove what
matters yourself.

| | Way A: with Sysprep | Way B: without Sysprep |
|---|---|---|
| Supported by Microsoft | yes, the only supported way to duplicate an installation | no |
| First boot of a clone | *specialize* pass, one restart, OOBE, then Cloudbase-Init (about 1.5 min plus the clone, measured) | a normal boot, Cloudbase-Init, one restart for the computer name (not measured; expected clearly shorter) |
| Machine SID | new on every clone | **the same on every server** |
| Remote Desktop certificate | new | new, if you delete it in the template (step B2) |
| Windows Update / WSUS client ID | new | new, if you delete it in the template (step B2) |
| KMS activation | works (new client machine ID) | every clone looks like the same client to a KMS host; use MAK, AVMA or retail keys |
| Drivers | detected again on the first boot (unless `PersistAllDeviceInstalls`) | already installed |
| Template building | Sysprep may stop on Store apps (Edge) | no Sysprep errors |

**What the shared SID means.** For standalone servers that never authenticate to each other,
duplicate machine SIDs have long caused little trouble. That is no longer true everywhere:
Microsoft's release notes say that since the September 2025 updates, Windows 11 24H2 and later and
**Windows Server 2025** fail NTLM and Kerberos authentication between machines with the same SID
(for example a file share between two of a customer's servers), and that this is by design.
Domain controllers and some management software never accepted duplicate SIDs.

**Which to choose.** Way A for anything you sell broadly, and always for Windows Server 2025 and
later. Way B only for Windows Server 2019 and 2022, when first-boot time matters more than support,
and when you accept that servers built from one template share their SID.

The panel works the same way with both; it needs no setting for it.

## Build the base system

Steps 1–5 are the same for both ways.

### 1. ISOs

On a storage with the **ISO image** content type (*Node → `local` → ISO Images → Upload* or
*Download from URL*):

- the **Windows Server** ISO (2019, 2022 or 2025; evaluation or your volume licensing media), and
- the **VirtIO drivers** ISO, stable release from the Fedora project:
  `https://fedorapeople.org/groups/virt/virtio-win/direct-downloads/stable-virtio/virtio-win.iso`.

### 2. Create the VM

*Create VM*, with a VMID outside the range of your servers (e.g. 9000 and up) and a name such as
`tpl-windows-2022-sysprep-20261027`. Each setting has a reason:

| Tab | Setting | Why |
|---|---|---|
| General | *Resource Pool*: the panel's pool, if its API token is limited to one | The token only clones what it can see |
| OS | The Windows Server ISO; *Type* **Microsoft Windows** with the matching version; tick **Add additional drive for VirtIO drivers** with `virtio-win.iso` | The OS type decides the config drive format and that the password reaches Cloudbase-Init |
| System | *Machine* q35, *BIOS* **OVMF (UEFI)** with an EFI disk (pre-enrolled keys), *SCSI Controller* VirtIO SCSI single, **QEMU Agent** on. Windows Server needs no TPM | Modern firmware; the agent needs its VirtIO serial port |
| Disks | Bus SCSI, **40 GiB** (Windows Server needs at least 32), **Discard** and **SSD emulation** on | The panel grows the disk per package; *Discard* lets the template shrink (step A3/B3) |
| CPU | 1 socket, **4 cores** while you build, *Type* default (`x86-64-v2-AES`), not `host` | Updates install faster; `host` would tie clones to one CPU model. The panel sets each server's count |
| Memory | 4096 MiB, ballooning off | The panel sets each server's value |
| Network | Model VirtIO, a bridge with internet access | Updates. Cloudbase-Init replaces this configuration on every clone |

### 3. Install Windows

1. Press a key when the console shows *Press any key to boot from CD or DVD*.
2. Choose the *Desktop Experience* or *Core* edition you are licensed for.
3. At *Where do you want to install Windows?* no disk is listed, because Windows has no driver for
   VirtIO SCSI: *Load driver* → the VirtIO CD → `vioscsi\<your Windows version>\amd64`. Install to the
   disk that appears. Windows creates its own partitions with `C:` last, which is what lets
   `C:` grow later.
4. Set a temporary Administrator password and log in.

### 4. Prepare Windows

1. On the VirtIO CD run **`virtio-win-guest-tools.exe`**: all VirtIO drivers (network, balloon,
   serial) and the QEMU guest agent.
2. Install all Windows updates and restart until *Settings → Windows Update* offers nothing and
   asks for no restart. Updates still pending in the template finish on **every** new server, with
   extra restarts.
3. In PowerShell as Administrator, turn on what a VPS customer expects and turn off what only
   slows a server down:

   ```powershell
   # Remote Desktop (off by default on Windows Server) and its firewall rules; the group is
   # named by its resource string so this works in every display language
   Set-ItemProperty 'HKLM:\System\CurrentControlSet\Control\Terminal Server' -Name fDenyTSConnections -Value 0
   Enable-NetFirewallRule -Group '@FirewallAPI.dll,-28752'
   # Answer ping (IPv4 and IPv6), useful for customers' monitoring
   Enable-NetFirewallRule -Name FPS-ICMP4-ERQ-In, FPS-ICMP6-ERQ-In
   # No Server Manager window at every logon, no hibernation file, high-performance power plan
   Get-ScheduledTask -TaskName ServerManager | Disable-ScheduledTask
   powercfg /hibernate off
   powercfg /setactive SCHEME_MIN
   ```

   Remote Desktop keeps Network Level Authentication on. Leave the Windows firewall itself on; the
   panel filters outside the VM with the Proxmox firewall. Answering ping is a choice.

4. **Check the agent** from the node's shell, while the VM runs:

   ```bash
   qm guest cmd <vmid> ping
   qm guest exec <vmid> -- powershell -NoProfile -Command '$PSVersionTable.PSVersion'
   ```

   The panel's scripts and Windows apps need Windows PowerShell 5.1 (Windows Server 2016 and later).

### 5. Install Cloudbase-Init

Download it from `https://cloudbase.it/cloudbase-init/` and run the installer: user name
`Administrator`, tick *Run Cloudbase-Init service as LocalSystem*.

The last page of the installer offers to run Sysprep. **Way A:** do not click *Finish* yet, the
next steps need it. **Way B:** leave both boxes unticked and click *Finish*.

Its configuration lives in `C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\`:

| File | When it is used |
|---|---|
| `cloudbase-init.conf` | by the **service**, at every boot (both ways) |
| `cloudbase-init-unattend.conf` | by Windows setup's *specialize* pass (Way A only) |
| `Unattend.xml` | the answer file Sysprep hands to Windows setup (Way A only) |

## Way A: with Sysprep

### A1. Configure Cloudbase-Init

The aim: everything a clone needs is set **during the specialize pass**, whose restart Windows does
anyway, so no further restart is needed; the service then only sets the network and the password.

`cloudbase-init-unattend.conf`, run in the specialize pass. It sets the computer name there (taking
effect with Windows' own restart), extends `C:`, and never restarts by itself:

```ini
[DEFAULT]
username=Administrator
groups=Administrators
inject_user_password=true
first_logon_behaviour=no
metadata_services=cloudbaseinit.metadata.services.configdrive.ConfigDriveService
check_latest_version=false
log_dir=C:\Program Files\Cloudbase Solutions\Cloudbase-Init\log\
log_file=cloudbase-init-unattend.log
plugins=cloudbaseinit.plugins.common.sethostname.SetHostNamePlugin,cloudbaseinit.plugins.windows.extendvolumes.ExtendVolumesPlugin,cloudbaseinit.plugins.common.userdata.UserDataPlugin
allow_reboot=false
stop_service_on_exit=false

[config_drive]
types=iso
locations=cdrom
```

`cloudbase-init.conf`, the service, at every boot. Password first, then the network:

```ini
[DEFAULT]
username=Administrator
groups=Administrators
inject_user_password=true
first_logon_behaviour=no
metadata_services=cloudbaseinit.metadata.services.configdrive.ConfigDriveService
check_latest_version=false
mtu_use_dhcp_config=false
ntp_use_dhcp_config=false
log_dir=C:\Program Files\Cloudbase Solutions\Cloudbase-Init\log\
log_file=cloudbase-init.log
local_scripts_path=C:\Program Files\Cloudbase Solutions\Cloudbase-Init\LocalScripts\
plugins=cloudbaseinit.plugins.common.setuserpassword.SetUserPasswordPlugin,cloudbaseinit.plugins.common.networkconfig.NetworkConfigPlugin,cloudbaseinit.plugins.common.sshpublickeys.SetUserSSHPublicKeysPlugin,cloudbaseinit.plugins.common.localscripts.LocalScriptsPlugin
allow_reboot=true
stop_service_on_exit=true

[config_drive]
types=iso
locations=cdrom
```

`Unattend.xml`: change two parts and leave the rest as Cloudbase-Init installed it.

1. In the `specialize` pass, the Cloudbase-Init command must not ask for a restart. Replace
   `&amp;&amp; exit 1 || exit 2` with `&amp; exit 0` and `OnRequest` with `Never` (keep `&amp;` as it
   is: a bare `&` breaks the XML):

   ```xml
   <RunSynchronousCommand wcm:action="add">
     <Order>1</Order>
     <Path>cmd.exe /c ""C:\Program Files\Cloudbase Solutions\Cloudbase-Init\Python\Scripts\cloudbase-init.exe" --config-file "C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\cloudbase-init-unattend.conf" &amp; exit 0"</Path>
     <Description>Run Cloudbase-Init to set the hostname</Description>
     <WillReboot>Never</WillReboot>
   </RunSynchronousCommand>
   ```

2. *Optional:* add a `generalize` pass (directly inside `<unattend>`, next to the other
   `<settings>`) that keeps the installed drivers, so the first boot does not detect every device
   again. Microsoft allows it only when every destination has exactly the hardware of the
   reference machine: use it only while every server keeps the template's virtual hardware (same
   disk bus, network model and machine type), and leave it out when in doubt:

   ```xml
   <settings pass="generalize">
     <component name="Microsoft-Windows-PnpSysprep" processorArchitecture="amd64" publicKeyToken="31bf3856ad364e35" language="neutral" versionScope="nonSxS" xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
       <PersistAllDeviceInstalls>true</PersistAllDeviceInstalls>
     </component>
   </settings>
   ```

Why each setting is there: [Configuration files explained](#configuration-files-explained).

### A2. Make Sysprep's job possible

Sysprep refuses to generalize when a Store app was updated for one user but not provisioned for
all. Edge updates itself while the VM is online, so remove its per-user package (Edge itself stays
installed):

```powershell
Get-AppxPackage -AllUsers *MicrosoftEdge.Stable* | Remove-AppxPackage -AllUsers
```

If Sysprep still stops, `C:\Windows\System32\Sysprep\Panther\setuperr.log` names the package.

### A3. Shrink

Every GB still used on the template disk is copied for every new server (full clone):

```powershell
# Superseded update files (afterwards installed updates can no longer be uninstalled)
Dism.exe /Online /Cleanup-Image /StartComponentCleanup /ResetBase
# Temporary files and the update download cache
Remove-Item "$env:TEMP\*", 'C:\Windows\Temp\*' -Recurse -Force -ErrorAction SilentlyContinue
Stop-Service wuauserv; Remove-Item 'C:\Windows\SoftwareDistribution\Download\*' -Recurse -Force -ErrorAction SilentlyContinue
# Tell the storage which blocks are free (needs Discard on the disk)
Optimize-Volume -DriveLetter C -ReTrim -Verbose
```

### A4. Generalize

Back in the Cloudbase-Init installer, tick **Run Sysprep to create a generalized image** and
**Shutdown when Sysprep terminates**, and click *Finish*. (Without the installer, run the command
from [Updating a template](#updating-a-template).) The VM generalizes itself and shuts down.

**Do not start it again**: its first boot would run the specialize pass and OOBE, and the template
would no longer be generalized. Continue with [Turn the VM into a template](#turn-the-vm-into-a-template).

## Way B: without Sysprep

### B1. Configure Cloudbase-Init

Without the specialize pass, the **service** does everything on the clone's first boot: password,
network, `C:` extension, and the computer name. Windows applies a new computer name only with a
restart, so the plugin that sets it (`UserDataPlugin`) goes **last** and the service may restart
(`allow_reboot=true`); after the restart the plugins already done are skipped and the service
stops.

Replace `cloudbase-init.conf` with:

```ini
[DEFAULT]
username=Administrator
groups=Administrators
inject_user_password=true
first_logon_behaviour=no
metadata_services=cloudbaseinit.metadata.services.configdrive.ConfigDriveService
check_latest_version=false
mtu_use_dhcp_config=false
ntp_use_dhcp_config=false
log_dir=C:\Program Files\Cloudbase Solutions\Cloudbase-Init\log\
log_file=cloudbase-init.log
local_scripts_path=C:\Program Files\Cloudbase Solutions\Cloudbase-Init\LocalScripts\
plugins=cloudbaseinit.plugins.common.setuserpassword.SetUserPasswordPlugin,cloudbaseinit.plugins.common.networkconfig.NetworkConfigPlugin,cloudbaseinit.plugins.windows.extendvolumes.ExtendVolumesPlugin,cloudbaseinit.plugins.common.sshpublickeys.SetUserSSHPublicKeysPlugin,cloudbaseinit.plugins.common.localscripts.LocalScriptsPlugin,cloudbaseinit.plugins.common.userdata.UserDataPlugin
allow_reboot=true
stop_service_on_exit=true

[config_drive]
types=iso
locations=cdrom
```

`cloudbase-init-unattend.conf` and `Unattend.xml` are not used on this way; leave them as they are.

Keep the `cloudbase-init` service's startup type **Automatic**, not *Automatic (Delayed Start)*:
without a setup pass to wait for, the panel decides that Windows is ready once the service has
stopped, and a service that has not started yet looks stopped.

### B2. Remove what must differ per server

These are the values Sysprep would have reset that matter for a VPS. In PowerShell as
Administrator:

```powershell
# Remote Desktop certificate: Windows creates a new one when the service starts on a clone
Remove-Item -Path 'Cert:\LocalMachine\Remote Desktop\*' -Force
# Windows Update client ID: WSUS and update reports would see every server as one
Stop-Service wuauserv
Remove-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate' -Name SusClientId, SusClientIdValidation -ErrorAction SilentlyContinue
# Cloudbase-Init's memory of instances it has seen, and its logs
Remove-Item 'HKLM:\SOFTWARE\Cloudbase Solutions\Cloudbase-Init\*' -Recurse -ErrorAction SilentlyContinue
Remove-Item 'C:\Program Files\Cloudbase Solutions\Cloudbase-Init\log\*' -ErrorAction SilentlyContinue
# Event logs of the build
wevtutil el | ForEach-Object { wevtutil cl "$_" 2>$null }
```

What stays shared and cannot be fixed without Sysprep: the machine SID (see the
[comparison](#making-clones-unique-with-or-without-sysprep)) and the KMS client machine ID.

The template's Administrator password stays on the clone until Cloudbase-Init replaces it, which is
why `SetUserPasswordPlugin` runs before the network is configured. Still use a long random
password for the template.

### B3. Shrink and shut down

Run the commands of [A3](#a3-shrink) (no Edge step is needed: there is no Sysprep), then shut the
VM down normally:

```powershell
Stop-Computer
```

**Do not start it again** before converting it: Cloudbase-Init and Windows would write new
values again, and B2 would have to be repeated. Continue with
[Turn the VM into a template](#turn-the-vm-into-a-template).

## Turn the VM into a template

With the VM stopped:

1. *Hardware*: remove **both** CD/DVD drives.
2. *Hardware → Add → CloudInit Drive*, on the same storage as the disk. Leave the *Cloud-Init* tab
   empty: the panel fills it in for every server. (Never add the drive while the VM can still
   boot as a template: Cloudbase-Init would apply it.)
3. *Options*: *Boot Order* only the disk (`scsi0`), *QEMU Guest Agent* enabled.
4. *More → Convert to template*.

On a cluster with **local** storage, the template is needed on every node that hosts servers
(clone it there with *Clone → Target node*, mode *Full clone*, and convert the clone). With
**shared** storage one is enough. A **linked clone** additionally needs the template on the very
storage the server's disk goes to.

## Add it to the panel and test it

1. If the panel's API token is limited to a resource pool, add the template to that pool
   (*Datacenter → Permissions → Pools → `<pool>` → Members*).
2. *Admin → Clusters*: run a sync.
3. *Admin → OS templates*: under *Operating systems* add the system with the type **Windows** (for
   example "Windows Server") and its version (2022), then add the OS template with that version and
   map the Proxmox template to it. The type **Windows** is what makes the panel treat its servers as
   Windows. Choose the *Clone method* (see [How fast](#how-fast-a-new-server-can-be-ready)).
4. Create **two** test servers and check:

| Check | Expected |
|---|---|
| The task's *wait for the guest agent* step | ends about when the console shows the logon screen; the server becomes *Active* |
| **Remote Desktop** as `Administrator` with the password the panel shows | logs in; the template's password does not |
| `hostname`, `ipconfig`, `Get-Volume C` | the server's name, the panel's address, the package's disk size |
| Restarts: `Get-WinEvent -FilterHashtable @{LogName='System'; Id=1074} -MaxEvents 5 \| Format-List TimeCreated, Message` | Way A: one by `winlogon.exe`, none by Cloudbase-Init (`python.exe`). Way B: one by Cloudbase-Init for the computer name |
| Way A only: `whoami /user` on both servers | the part of the SID before the last `-` differs |
| Way B: the Remote Desktop certificate's thumbprint on both servers (`Get-ChildItem 'Cert:\LocalMachine\Remote Desktop'`) | differs |

Licensing and activation (SPLA, AVMA, KMS) are your responsibility; the template contains no
product key unless you add one.

## Configuration files explained

- **Plugins of the service.** The default `CreateUserPlugin` only set the password of the existing
  Administrator again and took 10 s; `SetUserPasswordPlugin` sets it from the metadata
  (`admin_pass`) in a fraction of a second. This relies on the built-in `Administrator` account
  existing and being enabled, as it is on Windows Server: a template whose Administrator was
  disabled or renamed needs `CreateUserPlugin` back, or the password is never set.
- **Password before network.** Until `SetUserPasswordPlugin` runs, the template's password is
  valid; running it before `NetworkConfigPlugin` means the server is not reachable on its address
  while that is so.
- **`ExtendVolumesPlugin`** grows `C:` to the disk the panel created: in the specialize pass on
  Way A, in the service on Way B. Later disk upgrades are extended by the panel's `disk.ps1`.
- **`UserDataPlugin`** sets the computer name. Proxmox writes the hostname only into the user-data
  (`#cloud-config`, `hostname: …`); `meta_data.json` has none, so `SetHostNamePlugin` alone finds no
  name. Of Proxmox's user-data only `hostname` works on Windows; `user`, `password`, `chpasswd`,
  `fqdn`, `disable_root` and `manage_etc_hosts` are logged as *not supported*, so nothing is lost.
  Windows applies a name at the next restart: on Way A that is the restart of the specialize pass,
  on Way B the service's own restart, which is why it is the last plugin there.
- **`metadata_services`** with only `ConfigDriveService` keeps Cloudbase-Init from trying network
  metadata services (EC2, OpenStack HTTP), which only time out on Proxmox (about two minutes).
- **`[config_drive]`**: Proxmox's CloudInit drive is an ISO on a CD drive; `vfat` on `hdd` only
  logs *"mtools_path" needs to be provided*. These options replace the deprecated
  `config_drive_cdrom`, `config_drive_vfat` and `config_drive_raw_hdd`.
- **`allow_reboot`**: `false` in the unattend file, because a restart in the specialize pass breaks
  Sysprep; `true` in the service, which on Way B restarts for the computer name.
- **`stop_service_on_exit=true`**: the service stops when it is done; the panel waits for exactly
  that.
- **`WillReboot` `Never` and `& exit 0`** (Way A): Cloudbase-Init's own `Unattend.xml` ends with
  `&& exit 1 || exit 2` and `OnRequest`, and exit code 1 asks Windows setup for an extra restart even
  when Cloudbase-Init succeeded. A failed run in the specialize pass is then no longer repeated; only
  the computer name is skipped (password and network come from the service). Its log is
  `cloudbase-init-unattend.log`.
- **`PersistAllDeviceInstalls`** (optional, Way A) keeps the devices of the template, which match
  every clone as long as its virtual hardware is not changed, and should shorten "Getting ready"
  (*not measured yet*). Core count, memory size and MAC address are not hardware differences in
  this sense; a different disk bus or network model is.
- Scripts in **`LocalScripts\`** run at the first boot and delay everything after them; the log
  shows how long each took (`Script "…" ended with exit code`). Avoid fixed waits there.

## How fast a new server can be ready

Measured on Windows Server 2022, 2 vCPU, 4 GiB, LVM-thin (2026-10-20), Way A:

| Phase | Time | What decides it |
|---|---|---|
| Full clone of the template | 2:00 | Used size of the template disk and storage speed. A **linked clone** takes seconds |
| First boot: specialize pass ("Getting ready"), then the one restart | 1:01 | vCPUs; device installation |
| Second boot until OOBE is done | 0:20 | vCPUs |
| Cloudbase-Init service (network, password) | 0:26 | 10 s of it was `CreateUserPlugin`, which this guide removes (not measured since) |
| Panel notices that setup has finished | a few seconds | It checks every 5 s |

Way B skips the specialize pass and OOBE (about 1:20 of the above) but adds a restart for the
computer name; it has not been measured.

The levers, largest first:

1. **Clone method *Linked clone*** on the OS template ([ADR 0027](../adr/0027-linked-clones.md)):
   about 2 minutes less. Servers then depend on the template; read the ADR first.
2. **A small template** (A3): a full clone copies only the used blocks of a thin disk.
3. **More vCPUs** in the package: the setup passes are mostly CPU work.
4. **Way B** instead of Way A, with its costs.
5. **No restart beyond the necessary one** and **no unneeded Cloudbase-Init plugins**.

## Updating a template

Refresh the template monthly for security updates.

**Way A:**

1. *Clone* the template (mode **Full clone**), start the clone and log in on the console. It runs
   its first-boot setup once; set a password when asked.
2. Install the updates and restart until none is pending, then run A2 and A3.
3. Generalize it with Cloudbase-Init's answer file and let it shut down:

   ```bat
   C:\Windows\System32\Sysprep\sysprep.exe /generalize /oobe /shutdown /unattend:"C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\Unattend.xml"
   ```

**Way B:**

1. *Clone* the template (mode **Full clone**). In the clone's *Cloud-Init* tab set a temporary
   *Password*, start it and log in on the console (Cloudbase-Init applies the password and
   restarts once for the name).
2. Install the updates and restart until none is pending, then run B2 and B3.

**Both:** do [Turn the VM into a template](#turn-the-vm-into-a-template) with a new dated name, sync
the cluster and map the OS template to the new template. Delete the old template once nothing uses
it. Full clones do not depend on it; while **linked clones** use its base disk, Proxmox refuses to
delete it, so keep it until those servers are reinstalled or terminated.

## What the panel does for Windows

The operating system's type **Windows** makes the OS template's platform `windows`. The platform
decides:

| Operation | How it works on Windows |
|---|---|
| First boot | The config drive carries the password (`admin_pass`), the network settings and the hostname (in the user-data). Cloudbase-Init applies them |
| Additional IP addresses, primary address | The default `addresses.ps1` guest script (ADR 0010, 0011), run through the QEMU guest agent. It registers a **scheduled task "Proxmotion addresses"** that applies the addresses again at every startup |
| Disk grown by an upgrade | The default `disk.ps1` extends the `C:` partition |
| Password reset | The guest agent sets the password of **`Administrator`** (or the template's own `password_reset` script) |
| Hostname change | The default `hostname.ps1` renames the computer to the first label of the hostname (at most 15 characters). Windows applies it at the next restart: the panel restarts the server when asked, otherwise it shows *restart required* (ADR 0012) |
| Apps | Windows apps only (IIS, SQL Server Express, Chocolatey and recipes with `platform: windows`), run by `run.ps1` as a **scheduled task under `\Proxmotion\`** as SYSTEM (ADR 0029). Windows PowerShell 5.1 is needed |

Scripts can be replaced per template under *OS templates → Scripts*.

**When a new server is ready.** The guest agent already answers during the specialize pass, long
before Windows is usable. So for a new or reinstalled Windows server the task's *wait for the guest
agent* step goes on, checking through the agent every few seconds, until

- `ImageState` under `HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Setup\State` is
  `IMAGE_STATE_COMPLETE` (on Way B it already is; a template without the value counts as complete),
  and
- the `cloudbase-init` service is no longer running (`Stopped`, or not installed).

Restarts in between are waited out. Only then is the server *Active* and do the address script and
the template's own script run. When the agent has not answered at all after 10 minutes (a template
without an agent), the step ends with that note as on Linux; when setup has not finished after 15
minutes, the step notes *Windows setup did not finish within 15 minutes* and the task goes on.
Should a restart still interrupt a script, the panel waits for the agent and runs the script again
(up to three times), and the Windows scripts wait for Cloudbase-Init before they touch the network.

**Why a Way A server restarts once.** Windows setup always ends the specialize pass with a restart
before OOBE (`C:\Windows\Panther\UnattendGC\setupact.log`: *Reboot required after setup.exe and
PostSysprep commands, before launching OOBE*). Cloudbase-Init's defaults add two more, which the
configuration of A1 removes:

| Restart | Cause | Fix |
|---|---|---|
| Cloudbase-Init's service (`python.exe` in event 1074) | `UserDataPlugin` in the service sets the name and restarts (`allow_reboot=true`) | Run `UserDataPlugin` in the specialize pass |
| `winlogon.exe` in the middle of the specialize pass (`Unattend action requested immediate reboot and recall` in `C:\Windows\Panther\setupact.log`) | `<WillReboot>OnRequest</WillReboot>` and `&& exit 1 \|\| exit 2` in `Unattend.xml` | `<WillReboot>Never</WillReboot>` and `& exit 0` |

## Troubleshooting

| What you see | What it means / what to do |
|---|---|
| Remote Desktop does not connect | Remote Desktop is off by default on Windows Server: step 4. Also check that the panel's address is set (`ipconfig`) |
| Way A: two restarts, both `winlogon.exe` | `Unattend.xml` still asks for a restart after Cloudbase-Init: A1 |
| Way A: a restart by `python.exe` (Cloudbase-Init) | `UserDataPlugin` is in `cloudbase-init.conf` instead of `cloudbase-init-unattend.conf` |
| Way B: the computer name stays the template's | `UserDataPlugin` is missing, or `allow_reboot=false`: B1. `cloudbase-init.log` shows `Changing hostname` |
| Way B: the template's password works on a new server | `SetUserPasswordPlugin` did not run: Cloudbase-Init believes it already handled this instance (registry not cleared in B2) or the VM's OS type is not Windows |
| Way B: Remote Desktop warns about the same certificate on every server | The certificate was not deleted in B2 |
| "Getting ready" takes long, *Working on updates* | Updates were still pending in the template |
| Sysprep stops: *Package Microsoft.MicrosoftEdge.Stable_… was installed for a user, but not provisioned for all users* | A2; `C:\Windows\System32\Sysprep\Panther\setuperr.log` names the package |
| Hostname still `WIN-…` (Way A) | `cloudbase-init-unattend.log` should show `Changing hostname` |
| Password not set | `cloudbase-init.log` should show `Using admin_pass metadata user password`. The VM needs a CloudInit drive, and its OS type must be Windows |
| The task takes 15 minutes and notes *Windows setup did not finish* | The `cloudbase-init` service never stopped (`stop_service_on_exit=true` missing) or setup hangs: see the console and `cloudbase-init.log` |
| Way B: the panel ran its scripts before the network was set | The service started late (delayed start) and the panel saw it stopped: B1 |
| *The additional addresses could not be configured inside the server: QEMU guest agent is not running* | Windows restarted while the script ran, three times. The scheduled task applies the addresses at the next startup; or *Run on server* on the Scripts page |
| *Get-NetAdapter : Critical error* | Windows was still in its setup; current scripts wait for that. Update the template's override if it replaced the default |
| No scheduled task *Proxmotion addresses* | The address script never got far enough: `Get-ScheduledTask -TaskName 'Proxmotion addresses'` |

Where the time of a new server went (PowerShell as Administrator, on the new server):

```powershell
Get-WinEvent -FilterHashtable @{LogName='System'; Id=12} -MaxEvents 3 | Format-Table TimeCreated
Get-WinEvent -FilterHashtable @{LogName='System'; Id=1074} -MaxEvents 5 | Format-List TimeCreated, Message
Get-Content 'C:\Program Files\Cloudbase Solutions\Cloudbase-Init\log\cloudbase-init*.log' | Select-String 'Executing plugin|Plugins execution done|ended with|Rebooting'
Select-String -Path C:\Windows\Panther\UnattendGC\setupact.log -Pattern 'ImageState'
```

Event 12 is each boot, 1074 each restart and who asked for it, the Cloudbase-Init lines show how
long each plugin took, and `ImageState` when setup finished.

## Not verified yet

- **Way B has not been tried with the panel.** In particular: the computer name set by the service
  with its restart, the deletion of the Remote Desktop certificate and the Windows Update client
  ID, clearing Cloudbase-Init's registry key, and whether the panel's readiness check (service
  stopped) can pass before the service has started. The authentication failures between machines
  with the same SID are taken from Microsoft's release notes, not tried by us.
- Way A was measured once, on Windows Server 2022 (2026-10-20): a single restart with the
  `Unattend.xml` change, and the server *Active* a few seconds after the Cloudbase-Init service
  stopped. Not checked: Windows Server 2019 and 2025, Cloudbase-Init versions whose `Unattend.xml`
  differs, the plugin order with the password first.
- Not measured: `PersistAllDeviceInstalls`, the service without `CreateUserPlugin`, the shrinking
  commands, linked clones of a Windows template.
- Windows apps (ADR 0029) have only run against the panel's fake Proxmox.
- The Windows default scripts ran on one Windows Server image (2026-10-17): additional addresses
  after a reinstall. Primary address changes, `disk.ps1`, IPv6 and other Windows versions have not
  been checked.
