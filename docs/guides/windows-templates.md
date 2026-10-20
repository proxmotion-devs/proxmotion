# Windows templates and Cloudbase-Init

*Türkçe: [tr/windows-templates.md](tr/windows-templates.md)*

How Proxmotion handles Windows servers, how to configure Cloudbase-Init in a Windows template so
a new server is ready quickly with as few restarts as possible, and what to check when something
does not work. Building the template itself (VirtIO drivers, guest agent, Cloudbase-Init install,
Sysprep) is described in [iso-templates.md](iso-templates.md#windows-server).

## What the panel does for Windows

In *Admin → OS templates*, add the operating system (for example "Windows Server") with the type
**Windows** under *Operating systems*, add its version (2022) and pick that version for the OS
template: its platform is then `windows`. The platform decides:

| Operation | How it works on Windows |
|---|---|
| First boot | Proxmox's cloud-init drive (config drive) carries the password (`admin_pass`), the network settings and the hostname (in the user-data). Cloudbase-Init applies them |
| Additional IP addresses, primary address | The default `addresses.ps1` guest script (ADR 0010, 0011), run through the QEMU guest agent. It registers a **scheduled task "Proxmotion addresses"** that applies the addresses again at every startup |
| Disk grown by an upgrade | The default `disk.ps1` extends the `C:` partition |
| Password reset | The guest agent sets the password of **`Administrator`** (or the template's own `password_reset` script) |
| Hostname change | The default `hostname.ps1` renames the computer to the first label of the hostname (at most 15 characters). Windows applies it at the next restart: the panel restarts the server when asked, otherwise it shows *restart required* (ADR 0012) |

Scripts can be replaced per template under *OS templates → Scripts*.

Right after an install, the guest agent answers **before Windows has finished its setup**: it
already runs during Sysprep's *specialize* pass, and Sysprep and Cloudbase-Init still restart the
server afterwards. So for a new or reinstalled Windows server, the task's *wait for the guest
agent* step does not end when the agent first answers. Through the agent, the panel checks every
few seconds until

- `ImageState` under `HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Setup\State` is
  `IMAGE_STATE_COMPLETE` (a template that has no such value counts as complete), and
- the `cloudbase-init` service is no longer running (`Stopped`, or not installed).

Restarts in between, while the agent is away, are simply waited out. Only then is the server
*Active* and do the address script and the template's own script run. When the agent has not
answered at all after 10 minutes (a template without an agent), the step ends with that note as on
Linux; when setup has not finished after 15 minutes, the step notes *Windows setup did not finish
within 15 minutes* and the task goes on anyway (the server may still be setting itself up).

The scripts still cope with a late restart: when the agent goes away while a script runs, the
panel waits for it and runs the script again (up to three times), and the Windows scripts wait for
Cloudbase-Init to finish before they touch the network. Every restart you remove from the template
makes a new server ready sooner.

**Not verified on a real Windows guest yet:** the check above, and whether the agent can answer in
the short time between a restart and the start of the Cloudbase-Init service (which would let the
check pass too early; the scripts' retries cover that case). Unsuspending a server only waits for
the agent.

## Why a new Windows server restarts

A server cloned from a Sysprep-generalized template restarts **at least once**: Windows Setup
ends the *specialize* pass with a restart before it starts OOBE (`setupact.log` under
`C:\Windows\Panther\UnattendGC`: *Reboot required after setup.exe and PostSysprep commands, before
launching OOBE*). No setting removes it.

Two more restarts come from Cloudbase-Init's defaults and can be removed:

| Restart | Cause | Fix |
|---|---|---|
| Cloudbase-Init's service (`python.exe` in event 1074) at the first boot | `UserDataPlugin` sets the hostname from the user-data at the very end (`Changing hostname to '…'`, then `Rebooting`), and the service may restart (`allow_reboot=true`) | Run `UserDataPlugin` in the specialize pass instead (`cloudbase-init-unattend.conf` below): the rename then takes effect with Windows Setup's own restart |
| `winlogon.exe` in the middle of the specialize pass (`Unattend action requested immediate reboot and recall` in `C:\Windows\Panther\setupact.log`) | Cloudbase-Init's `Unattend.xml` runs it in the specialize pass with `<WillReboot>OnRequest</WillReboot>` and `&& exit 1 \|\| exit 2`: exit code 1 asks Windows Setup to restart, so it restarts even when Cloudbase-Init succeeded | `<WillReboot>Never</WillReboot>` and `& exit 0` (`Unattend.xml` below) |

Why the hostname needs the user-data plugin at all: Proxmox writes the hostname **only into the
user-data** (`#cloud-config`, `hostname: …`); `meta_data.json` holds only `uuid`, `admin_pass` and
the SSH keys. So `SetHostNamePlugin`, which reads the metadata, finds no name and does nothing.

Measured on Windows Server 2022 (2026-10-20): with all three fixes a new server restarted once.
Before the `Unattend.xml` fix it restarted twice (both `winlogon.exe`), and setup took about
2.5 minutes from the first boot until Cloudbase-Init's service had set the network and the
password. If a new Windows server still takes much longer to become ready, look at the task's
*clone* step: a full clone of a large Windows disk can take minutes on slow storage.

## Recommended configuration

All three files are in `C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\`.

`cloudbase-init-unattend.conf` (runs during Sysprep's specialize pass; never restarts by itself):

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

`cloudbase-init.conf` (the service, at every boot):

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
plugins=cloudbaseinit.plugins.windows.createuser.CreateUserPlugin,cloudbaseinit.plugins.common.networkconfig.NetworkConfigPlugin,cloudbaseinit.plugins.common.sshpublickeys.SetUserSSHPublicKeysPlugin,cloudbaseinit.plugins.windows.extendvolumes.ExtendVolumesPlugin,cloudbaseinit.plugins.common.setuserpassword.SetUserPasswordPlugin,cloudbaseinit.plugins.common.localscripts.LocalScriptsPlugin
allow_reboot=true
stop_service_on_exit=true

[config_drive]
types=iso
locations=cdrom
```

`Unattend.xml`: in the `specialize` pass, change only the Cloudbase-Init command so it never asks
for a restart (keep `&amp;` as it is: a bare `&` breaks the XML):

```xml
<RunSynchronousCommand wcm:action="add">
  <Order>1</Order>
  <Path>cmd.exe /c ""C:\Program Files\Cloudbase Solutions\Cloudbase-Init\Python\Scripts\cloudbase-init.exe" --config-file "C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\cloudbase-init-unattend.conf" &amp; exit 0"</Path>
  <Description>Run Cloudbase-Init to set the hostname</Description>
  <WillReboot>Never</WillReboot>
</RunSynchronousCommand>
```

Notes:

- Nothing is lost by moving `UserDataPlugin`: of Proxmox's user-data only `hostname` works on
  Windows; `user`, `password`, `chpasswd`, `fqdn`, `disable_root` and `manage_etc_hosts` are
  reported as *not supported* in the log. The password comes from the metadata
  (`CreateUserPlugin`, `SetUserPasswordPlugin`), the network from `NetworkConfigPlugin`.
- `metadata_services` with only `ConfigDriveService` keeps Cloudbase-Init from trying network
  metadata services (EC2, OpenStack HTTP), which only time out on Proxmox (about two minutes).
- Proxmox's CloudInit drive is an ISO on a CD drive, so `[config_drive]` looks only there;
  `vfat` on `hdd` only logs *"mtools_path" needs to be provided*. These options replace the old
  `config_drive_cdrom`, `config_drive_vfat` and `config_drive_raw_hdd`, which the log reports as
  deprecated.
- Keep `allow_reboot=false` in the unattend file: a restart there would break Sysprep.
- With `WillReboot` set to `Never`, a failed Cloudbase-Init run in the specialize pass is no
  longer restarted and repeated; only the hostname is skipped then (password and network come from
  the service at the first boot). Its log is `cloudbase-init-unattend.log`.
- Your own scripts in `LocalScripts\` run at the first boot and delay everything after them: the
  log shows how long each took (`Script "…" ended with exit code`). Avoid fixed waits there.

## Applying the configuration to a template

The files live inside the template image:

1. Create a full clone of the template (or start the VM the template was made from) and log in.
2. Edit the three files as above.
3. Right before Sysprep, remove Edge's per-user package. Edge updates itself as soon as the VM is
   online, and Sysprep then stops with *Package Microsoft.MicrosoftEdge.Stable_… was installed for a
   user, but not provisioned for all users* (`0x80073cf2` in
   `C:\Windows\System32\Sysprep\Panther\setuperr.log`). Edge itself stays installed:

   ```powershell
   Get-AppxPackage -AllUsers *MicrosoftEdge.Stable* | Remove-AppxPackage -AllUsers
   ```

4. Generalize it again with Cloudbase-Init's answer file and let it shut down:

   ```bat
   C:\Windows\System32\Sysprep\sysprep.exe /generalize /oobe /shutdown /unattend:"C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\Unattend.xml"
   ```

5. Convert the VM to a template in Proxmox (see [iso-templates.md](iso-templates.md#updating-a-template)).
   If it is a new VMID, sync the cluster in the panel and map the OS template to it.
6. Create a test server and check that the System log shows a single 1074 event from
   `winlogon.exe` (besides the template's own Sysprep shutdown) and none from Cloudbase-Init, and
   that `hostname` prints the server's name.

## Troubleshooting

| What you see | What it means / what to do |
|---|---|
| Task step *The additional addresses could not be configured inside the server: QEMU guest agent is not running* | Windows restarted while the script ran and did not come back three times. Check the event log (1074). The scheduled task applies the addresses at the next startup; or use *Run on server* on the Scripts page |
| *Get-NetAdapter : Critical error* | Windows was still in its setup; the network cmdlets were not ready. Current scripts wait for that; update the template's override if it replaced the default |
| No scheduled task *Proxmotion addresses* | The address script never got far enough. In PowerShell: `Get-ScheduledTask -TaskName 'Proxmotion addresses'` |
| Hostname still `WIN-…` | The user-data plugin did not run. Check `cloudbase-init-unattend.log` (or `cloudbase-init.log` with the old configuration) for `Changing hostname` |
| Password not set | `Using admin_pass metadata user password` should appear in `cloudbase-init.log`; the Proxmox VM needs a CloudInit drive |
| Two restarts, both `winlogon.exe` | `Unattend.xml` still asks for a restart after Cloudbase-Init (`<WillReboot>OnRequest</WillReboot>`, `exit 1`): see *Recommended configuration* |
| Sysprep stops: *Package Microsoft.MicrosoftEdge.Stable_… was installed for a user, but not provisioned for all users* | Edge updated itself on the clone. Remove its per-user package and run Sysprep again (step 3 of *Applying the configuration to a template*). Store apps can stop it the same way; `C:\Windows\System32\Sysprep\Panther\setuperr.log` names the package |

Useful commands inside the server (PowerShell as Administrator):

```powershell
Get-NetIPAddress -AddressFamily IPv4 | Format-Table IPAddress, PrefixLength, SkipAsSource
Get-WinEvent -FilterHashtable @{LogName='System'; Id=1074} -MaxEvents 5 | Format-List TimeCreated, Message
Get-Content 'C:\Program Files\Cloudbase Solutions\Cloudbase-Init\log\cloudbase-init.log' -Tail 40
Get-ChildItem C:\ProgramData\Proxmotion
```

## Not verified yet

- The Windows default scripts ran on one Windows Server image (2026-10-17): additional addresses
  after a reinstall with two setup restarts. Primary address changes, `disk.ps1`, IPv6 and other
  Windows versions have not been checked.
- The single restart with the configuration above was seen on one Windows Server 2022 template
  (2026-10-20); that the hostname is applied with it, Windows Server 2019 and 2025, and the
  configuration on a template whose Cloudbase-Init version writes a different `Unattend.xml`.
