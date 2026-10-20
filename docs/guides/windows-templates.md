# Windows templates

*Türkçe: [tr/windows-templates.md](tr/windows-templates.md)*

Everything about Windows templates in one place: building the template in Proxmox, configuring
Cloudbase-Init and Sysprep so a new server is ready quickly with a single restart, keeping the
template small so it clones fast, updating it, how the panel handles Windows servers, and what to
check when something does not work.

**Contents**

1. [How fast a new server can be ready](#how-fast-a-new-server-can-be-ready)
2. [Build the template](#build-the-template): [ISOs](#1-isos) · [VM](#2-create-the-vm) · [Install](#3-install-windows) · [Prepare](#4-prepare-windows) · [Cloudbase-Init](#5-install-and-configure-cloudbase-init) · [Shrink and Sysprep](#6-shrink-and-generalize) · [Template](#7-turn-the-vm-into-a-template) · [Panel](#8-add-it-to-the-panel)
3. [Configuration files](#configuration-files)
4. [Updating a template](#updating-a-template)
5. [What the panel does for Windows](#what-the-panel-does-for-windows)
6. [Why a new Windows server restarts](#why-a-new-windows-server-restarts)
7. [Troubleshooting](#troubleshooting)
8. [Not verified yet](#not-verified-yet)

## How fast a new server can be ready

Measured on Windows Server 2022, 2 vCPU, 4 GiB, LVM-thin (2026-10-20), with the configuration of
this guide except the items marked *not measured*:

| Phase | Time | What decides it |
|---|---|---|
| Full clone of the template | 2:00 | Used size of the template disk and storage speed. A **linked clone** takes seconds |
| First boot: Sysprep *specialize* pass ("Getting ready"), then the one restart | 1:01 | vCPUs; device installation (`PersistAllDeviceInstalls`, not measured) |
| Second boot until OOBE is done | 0:20 | vCPUs |
| Cloudbase-Init service (network, password) | 0:26 | 10 s of it was `CreateUserPlugin`, which this guide removes (not measured) |
| Panel notices that setup has finished | a few seconds | It checks every 5 s |

The levers, largest first:

1. **Clone method *Linked clone*** on the OS template ([ADR 0027](../adr/0027-linked-clones.md)):
   about 2 minutes less. Servers then depend on the template; read the ADR first.
2. **A small template** ([step 6](#6-shrink-and-generalize)): a full clone copies only the used
   blocks of a thin disk, so every GB removed from the template shortens it.
3. **More vCPUs** in the package: the setup passes are mostly CPU work.
4. **One restart instead of three** and **no unneeded Cloudbase-Init plugins**
   ([configuration files](#configuration-files)).

A Sysprep-generalized image always needs its *specialize* pass and one restart, so expect a
minimum of roughly 1.5 minutes from the first boot; less is only possible without Sysprep, which
Microsoft does not support (every server would share the machine SID and the Remote Desktop
certificate).

## Build the template

### 1. ISOs

On a storage with the **ISO image** content type (*Node → `local` → ISO Images → Upload* or
*Download from URL*):

- the **Windows Server** ISO (2019, 2022 or 2025; evaluation or your volume licensing media), and
- the **VirtIO drivers** ISO, stable release from the Fedora project:
  `https://fedorapeople.org/groups/virt/virtio-win/direct-downloads/stable-virtio/virtio-win.iso`.

### 2. Create the VM

*Create VM*, with a VMID outside the range of your servers (e.g. 9000 and up) and a name such as
`tpl-windows-2022-20261020`.

| Tab | Setting |
|---|---|
| General | *Resource Pool*: the panel's pool, if its API token is limited to one |
| OS | The Windows Server ISO. *Type* **Microsoft Windows**, the matching version: Proxmox writes the password for Cloudbase-Init only for a Windows OS type. Tick **Add additional drive for VirtIO drivers** and choose `virtio-win.iso` |
| System | *Machine* q35, *BIOS* **OVMF (UEFI)**, *Add EFI Disk* (pre-enrolled keys), *SCSI Controller* VirtIO SCSI single, **QEMU Agent** on. Windows Server needs no TPM |
| Disks | Bus SCSI, **40 GiB** (Windows Server needs at least 32; the panel grows the disk per package), **Discard** and **SSD emulation** on, on the storage your servers use |
| CPU | 1 socket, **4 cores** while you build (updates install faster; the panel sets each server's own count), *Type* default (`x86-64-v2-AES`), not `host`, so clones run on every node |
| Memory | 4096 MiB, ballooning off |
| Network | Model VirtIO, a bridge with internet access for the updates |

### 3. Install Windows

1. Press a key when the console shows *Press any key to boot from CD or DVD*.
2. Choose the *Desktop Experience* or *Core* edition you are licensed for.
3. At *Where do you want to install Windows?* no disk is listed: *Load driver* → the VirtIO CD →
   `vioscsi\<your Windows version>\amd64`. Install to the disk that appears.
4. Set a temporary Administrator password and log in.

### 4. Prepare Windows

1. On the VirtIO CD run **`virtio-win-guest-tools.exe`**: all VirtIO drivers and the QEMU guest
   agent.
2. Install all Windows updates and restart until *Settings → Windows Update* offers nothing and
   asks for no restart. Updates still pending when you generalize finish on **every** new server,
   with extra restarts.
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

   Remote Desktop keeps Network Level Authentication on. Leave the Windows firewall itself on.
   Answering ping is a choice: most VPS customers expect it, but leave that line out if you
   prefer servers that do not answer ICMP.

### 5. Install and configure Cloudbase-Init

Cloudbase-Init is the Windows counterpart of cloud-init: it reads the Proxmox CloudInit drive and
sets the Administrator password, the network and the computer name.

1. Download and install it from `https://cloudbase.it/cloudbase-init/`. In the installer use the
   user name `Administrator` and tick *Run Cloudbase-Init service as LocalSystem*. **Do not click
   Finish yet.**
2. Replace the three files in `C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\` as shown
   under [Configuration files](#configuration-files): `cloudbase-init-unattend.conf`,
   `cloudbase-init.conf`, and the two marked parts of `Unattend.xml`.

### 6. Shrink and generalize

Every GB still used on the template disk is copied for every new server (full clone). In
PowerShell as Administrator:

```powershell
# Edge updates itself while the VM is online; Sysprep then stops with 0x80073cf2
# ("installed for a user, but not provisioned for all users"). Edge itself stays installed.
Get-AppxPackage -AllUsers *MicrosoftEdge.Stable* | Remove-AppxPackage -AllUsers
# Superseded update files (afterwards installed updates can no longer be uninstalled)
Dism.exe /Online /Cleanup-Image /StartComponentCleanup /ResetBase
# Temporary files and the update download cache
Remove-Item "$env:TEMP\*", 'C:\Windows\Temp\*' -Recurse -Force -ErrorAction SilentlyContinue
Stop-Service wuauserv; Remove-Item 'C:\Windows\SoftwareDistribution\Download\*' -Recurse -Force -ErrorAction SilentlyContinue
# Tell the storage which blocks are free (needs Discard on the disk)
Optimize-Volume -DriveLetter C -ReTrim -Verbose
```

Then go back to the Cloudbase-Init installer, tick **Run Sysprep to create a generalized image**
and **Shutdown when Sysprep terminates**, and click *Finish*. The VM generalizes itself and
shuts down. **Do not start it again**: it would run its first-boot setup and the template would
be spoiled. (Without the installer, run the Sysprep command from
[Updating a template](#updating-a-template).)

### 7. Turn the VM into a template

1. *Hardware*: remove **both** CD/DVD drives.
2. *Hardware → Add → CloudInit Drive*, on the same storage as the disk. Leave the *Cloud-Init*
   tab empty: the panel fills it in for every server.
3. *Options*: *Boot Order* only the disk (`scsi0`), *QEMU Guest Agent* enabled.
4. *More → Convert to template*.

On a cluster with **local** storage, the template is needed on every node that hosts servers
(clone it there with *Clone → Target node*, mode *Full clone*, and convert the clone). With
**shared** storage one is enough. A **linked clone** additionally needs the template on the very
storage the server's disk goes to.

### 8. Add it to the panel

1. If the panel's API token is limited to a resource pool, add the template to that pool
   (*Datacenter → Permissions → Pools → `<pool>` → Members*).
2. *Admin → Clusters*: run a sync.
3. *Admin → OS templates*: under *Operating systems* add the system with the type **Windows**
   (for example "Windows Server") and its version (2022), then add the OS template with that
   version and map the Proxmox template to it. Choose the *Clone method* (see
   [How fast](#how-fast-a-new-server-can-be-ready)).
4. Create a test server and check:
   - the task's *wait for the guest agent* step ends about when the console shows the logon
     screen, and the server becomes *Active*;
   - you can log in with **Remote Desktop** as `Administrator` with the password the panel shows;
   - `hostname` prints the server's name, `C:` has the package size, `ipconfig` the panel's
     address;
   - the System log shows a single 1074 event from `winlogon.exe` besides the template's own
     Sysprep shutdown, and none from Cloudbase-Init:
     `Get-WinEvent -FilterHashtable @{LogName='System'; Id=1074} -MaxEvents 5 | Format-List TimeCreated, Message`.

Licensing and activation (SPLA, AVMA, KMS) are your responsibility; the template contains no
product key unless you add one.

## Configuration files

All three are in `C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\`.

`cloudbase-init-unattend.conf` runs during Sysprep's *specialize* pass. It sets the computer name
there, so the name takes effect with the restart Windows Setup does anyway. It never restarts by
itself:

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

`cloudbase-init.conf` is the service, at every boot. It sets the network and the password:

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
plugins=cloudbaseinit.plugins.common.networkconfig.NetworkConfigPlugin,cloudbaseinit.plugins.common.sshpublickeys.SetUserSSHPublicKeysPlugin,cloudbaseinit.plugins.common.setuserpassword.SetUserPasswordPlugin,cloudbaseinit.plugins.common.localscripts.LocalScriptsPlugin
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
   `<settings>`) that keeps the installed drivers, so the first boot does not detect and install
   every device again. This is a setting of Windows Setup, not of Cloudbase-Init. Microsoft allows
   it only when every destination has exactly the hardware of the reference machine: use it only
   while every server keeps the template's virtual hardware (same disk bus, network model and
   machine type), and leave it out when in doubt:

   ```xml
   <settings pass="generalize">
     <component name="Microsoft-Windows-PnpSysprep" processorArchitecture="amd64" publicKeyToken="31bf3856ad364e35" language="neutral" versionScope="nonSxS" xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
       <PersistAllDeviceInstalls>true</PersistAllDeviceInstalls>
     </component>
   </settings>
   ```

Why each setting:

- **Plugins of the service.** `CreateUserPlugin` only set the password of the existing
  Administrator again and took 10 s; `SetUserPasswordPlugin` sets it from the metadata
  (`admin_pass`) in a fraction of a second. `ExtendVolumesPlugin` already ran in the
  *specialize* pass; later disk upgrades are extended by the panel's `disk.ps1`. Both were
  therefore removed (*not measured yet*). This relies on the built-in `Administrator` account
  existing and being enabled, as it is on Windows Server: a template whose Administrator was
  disabled or renamed needs `CreateUserPlugin` back, or the password is never set.
- **`UserDataPlugin` in the specialize pass.** Proxmox writes the hostname only into the user-data
  (`#cloud-config`, `hostname: …`); `meta_data.json` holds only `uuid`, `admin_pass` and the SSH
  keys, so `SetHostNamePlugin` finds no name. Of Proxmox's user-data only `hostname` works on
  Windows; `user`, `password`, `chpasswd`, `fqdn`, `disable_root` and `manage_etc_hosts` are
  logged as *not supported*, so nothing is lost.
- **`metadata_services`** with only `ConfigDriveService` keeps Cloudbase-Init from trying network
  metadata services (EC2, OpenStack HTTP), which only time out on Proxmox (about two minutes).
- **`[config_drive]`**: Proxmox's CloudInit drive is an ISO on a CD drive; `vfat` on `hdd` only
  logs *"mtools_path" needs to be provided*. These options replace the deprecated
  `config_drive_cdrom`, `config_drive_vfat` and `config_drive_raw_hdd`.
- **`allow_reboot=false`** in the unattend file: a restart there would break Sysprep.
- **`WillReboot` `Never`**: a failed Cloudbase-Init run in the specialize pass is no longer
  restarted and repeated; only the hostname is skipped then (password and network come from the
  service). Its log is `cloudbase-init-unattend.log`.
- **`PersistAllDeviceInstalls`** (optional, Microsoft-Windows-PnpSysprep) keeps the devices of the
  template, which match every clone as long as the template's virtual hardware is not changed,
  and should shorten "Getting ready" (*not measured yet*). Microsoft warns that even small
  hardware differences can cause severe problems; core count, memory size and MAC address are
  not such differences, a different disk bus or network model is.
- Scripts in **`LocalScripts\`** run at the first boot and delay everything after them; the log
  shows how long each took (`Script "…" ended with exit code`). Avoid fixed waits there.

## Updating a template

Refresh the template monthly for security updates:

1. *Clone* the template (mode **Full clone**), start the clone and log in on the console. It runs
   its first-boot setup once; set a password when asked.
2. Install the updates and restart until none is pending ([step 4](#4-prepare-windows)), then run
   the PowerShell block of [step 6](#6-shrink-and-generalize).
3. Generalize it with Cloudbase-Init's answer file and let it shut down:

   ```bat
   C:\Windows\System32\Sysprep\sysprep.exe /generalize /oobe /shutdown /unattend:"C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\Unattend.xml"
   ```

4. Do [step 7](#7-turn-the-vm-into-a-template) with a new dated name, sync the cluster in the
   panel and map the OS template to the new template.
5. Delete the old template once nothing uses it. Full clones do not depend on it. While **linked
   clones** use its base disk, Proxmox refuses to delete it: keep it until those servers are
   reinstalled or terminated, and plan the storage for old templates.

## What the panel does for Windows

The operating system's type **Windows** makes the OS template's platform `windows`. The platform
decides:

| Operation | How it works on Windows |
|---|---|
| First boot | Proxmox's CloudInit drive (config drive) carries the password (`admin_pass`), the network settings and the hostname (in the user-data). Cloudbase-Init applies them |
| Additional IP addresses, primary address | The default `addresses.ps1` guest script (ADR 0010, 0011), run through the QEMU guest agent. It registers a **scheduled task "Proxmotion addresses"** that applies the addresses again at every startup |
| Disk grown by an upgrade | The default `disk.ps1` extends the `C:` partition |
| Password reset | The guest agent sets the password of **`Administrator`** (or the template's own `password_reset` script) |
| Hostname change | The default `hostname.ps1` renames the computer to the first label of the hostname (at most 15 characters). Windows applies it at the next restart: the panel restarts the server when asked, otherwise it shows *restart required* (ADR 0012) |
| Apps | Windows apps only (IIS, SQL Server Express, Chocolatey and recipes with `platform: windows`): the recipe is written to `C:\ProgramData\Proxmotion\apps\<installation>` (SYSTEM and Administrators only) and run by `run.ps1`, a **scheduled task under `\Proxmotion\`** running as SYSTEM, which restarts Windows when a script asks for it and runs the script again afterwards (ADR 0029). Windows PowerShell 5.1 is needed (Windows Server 2016 and later, Windows 10/11) |

Scripts can be replaced per template under *OS templates → Scripts*.

**When a new server is ready.** The guest agent already answers during Sysprep's *specialize*
pass, long before Windows is usable. So for a new or reinstalled Windows server the task's *wait
for the guest agent* step goes on, checking through the agent every few seconds, until

- `ImageState` under `HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Setup\State` is
  `IMAGE_STATE_COMPLETE` (a template that has no such value counts as complete), and
- the `cloudbase-init` service is no longer running (`Stopped`, or not installed).

Restarts in between are waited out. Only then is the server *Active* and do the address script
and the template's own script run. When the agent has not answered at all after 10 minutes (a
template without an agent), the step ends with that note as on Linux; when setup has not finished
after 15 minutes, the step notes *Windows setup did not finish within 15 minutes* and the task
goes on. Should a restart still interrupt a script, the panel waits for the agent and runs the
script again (up to three times), and the Windows scripts wait for Cloudbase-Init before they touch
the network. Unsuspending a server only waits for the agent.

## Why a new Windows server restarts

A server cloned from a Sysprep-generalized template restarts **at least once**: Windows Setup
ends the *specialize* pass with a restart before OOBE (`C:\Windows\Panther\UnattendGC\setupact.log`:
*Reboot required after setup.exe and PostSysprep commands, before launching OOBE*). Cloudbase-Init's
defaults add two more, which the [configuration files](#configuration-files) remove:

| Restart | Cause | Fix |
|---|---|---|
| Cloudbase-Init's service (`python.exe` in event 1074) at the first boot | `UserDataPlugin` sets the hostname at the very end (`Changing hostname to '…'`, then `Rebooting`), and the service may restart (`allow_reboot=true`) | Run `UserDataPlugin` in the specialize pass (`cloudbase-init-unattend.conf`) |
| `winlogon.exe` in the middle of the specialize pass (`Unattend action requested immediate reboot and recall` in `C:\Windows\Panther\setupact.log`) | Cloudbase-Init's `Unattend.xml` runs it with `<WillReboot>OnRequest</WillReboot>` and `&& exit 1 \|\| exit 2`: exit code 1 asks Windows Setup to restart, even when Cloudbase-Init succeeded | `<WillReboot>Never</WillReboot>` and `& exit 0` |

## Troubleshooting

| What you see | What it means / what to do |
|---|---|
| Remote Desktop does not connect | Remote Desktop is off by default on Windows Server: run the first two commands of [step 4](#4-prepare-windows) in the template (and on servers already cloned from it). Also check that the panel's address is set (`ipconfig`) |
| Two restarts, both `winlogon.exe` | `Unattend.xml` still asks for a restart after Cloudbase-Init (`<WillReboot>OnRequest</WillReboot>`, `exit 1`): see [Configuration files](#configuration-files) |
| A restart by `python.exe` (Cloudbase-Init) | The service still sets the hostname: `UserDataPlugin` belongs in `cloudbase-init-unattend.conf`, not in `cloudbase-init.conf` |
| "Getting ready" takes long, *Working on updates* | Updates were still pending when the template was generalized: update the template until none is pending |
| Sysprep stops: *Package Microsoft.MicrosoftEdge.Stable_… was installed for a user, but not provisioned for all users* | Edge updated itself. Remove its per-user package and run Sysprep again ([step 6](#6-shrink-and-generalize)). Store apps can stop it the same way; `C:\Windows\System32\Sysprep\Panther\setuperr.log` names the package |
| Hostname still `WIN-…` | The user-data plugin did not run. Check `cloudbase-init-unattend.log` for `Changing hostname` |
| Password not set | `Using admin_pass metadata user password` should appear in `cloudbase-init.log`. The VM needs a CloudInit drive, and its OS type must be Windows (otherwise Proxmox stores the password encrypted and Cloudbase-Init receives the encrypted text) |
| The task takes 15 minutes and notes *Windows setup did not finish* | The `cloudbase-init` service never stopped (`stop_service_on_exit=true` missing) or setup hangs: see the console and `cloudbase-init.log` |
| Task step *The additional addresses could not be configured inside the server: QEMU guest agent is not running* | Windows restarted while the script ran and did not come back three times. Check the event log (1074). The scheduled task applies the addresses at the next startup; or use *Run on server* on the Scripts page |
| *Get-NetAdapter : Critical error* | Windows was still in its setup; the network cmdlets were not ready. Current scripts wait for that; update the template's override if it replaced the default |
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

- Windows apps (ADR 0029) have only run against the panel's fake Proxmox: the scheduled task, the
  restart and resume, the log reader and the three bundled Windows recipes are untested on a real
  Windows guest.
- Measured once, on Windows Server 2022 (2026-10-20): a single restart with the `Unattend.xml`
  change, and the panel's setup check making the server *Active* a few seconds after the
  Cloudbase-Init service stopped. Not checked: that the hostname is applied with the single
  restart, Windows Server 2019 and 2025, and Cloudbase-Init versions whose `Unattend.xml` differs.
- Not measured: `PersistAllDeviceInstalls`, the service without `CreateUserPlugin` and
  `ExtendVolumesPlugin`, the shrinking commands of step 6, linked clones of a Windows template.
- The Remote Desktop and ping commands of step 4 have not been run by us; the firewall group
  `@FirewallAPI.dll,-28752` and the rule names `FPS-ICMP4-ERQ-In` / `FPS-ICMP6-ERQ-In` are
  Windows' built-in ones.
- The Windows default scripts ran on one Windows Server image (2026-10-17): additional addresses
  after a reinstall. Primary address changes, `disk.ps1`, IPv6 and other Windows versions have not
  been checked.
