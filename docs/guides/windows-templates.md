# Windows templates and Cloudbase-Init

*Türkçe: [tr/windows-templates.md](tr/windows-templates.md)*

How Proxmotion handles Windows servers, how to configure Cloudbase-Init in a Windows template so
a new server is ready quickly with as few restarts as possible, and what to check when something
does not work. Building the template itself (VirtIO drivers, guest agent, Cloudbase-Init install,
Sysprep) is described in [iso-templates.md](iso-templates.md#windows-server).

## What the panel does for Windows

Set the OS template's **Platform** to **Windows** in *Admin → OS templates* (templates of the
`windows` family get it automatically). The platform decides:

| Operation | How it works on Windows |
|---|---|
| First boot | Proxmox's cloud-init drive (config drive) carries the password (`admin_pass`), the network settings and the hostname (in the user-data). Cloudbase-Init applies them |
| Additional IP addresses, primary address | The default `addresses.ps1` guest script (ADR 0010, 0011), run through the QEMU guest agent. It registers a **scheduled task "Proxmotion addresses"** that applies the addresses again at every startup |
| Disk grown by an upgrade | The default `disk.ps1` extends the `C:` partition |
| Password reset | The guest agent sets the password of **`Administrator`** (or the template's own `password_reset` script) |

Scripts can be replaced per template under *OS templates → Scripts*.

Right after an install, the guest agent answers **before Windows has finished its setup**: Sysprep
and Cloudbase-Init still restart the server. The panel copes with that. When the agent goes away
while a script runs, it waits for it and runs the script again (up to three times). The Windows
scripts also wait for Cloudbase-Init to finish before they touch the network. So every restart you
remove from the template makes a new server ready sooner.

## Why a new Windows server restarts

On a template built as in the ISO guide, Cloudbase-Init's log
(`C:\Program Files\Cloudbase Solutions\Cloudbase-Init\log\cloudbase-init.log`) and the System event
log (event ID **1074**) show two restarts:

| # | Who | Why | Avoidable |
|---|---|---|---|
| 1 | `winlogon.exe` (Sysprep) | End of the *specialize* pass, on the way to OOBE | No: once per Sysprep-generalized image |
| 2 | Cloudbase-Init (`python.exe`) | `UserDataPlugin` sets the hostname from the user-data (`Changing hostname to '…'`, then `Rebooting`) | **Yes** |

Why the second one: Proxmox writes the hostname **only into the user-data** (`#cloud-config`,
`hostname: …`); `meta_data.json` holds only `uuid` and `admin_pass`. So `SetHostNamePlugin`, which
reads the metadata, finds no name and does nothing. The name is set at the very end by the user-data
plugin, a Windows rename needs a restart, and the service configuration allows restarts
(`allow_reboot=true`).

## Recommended configuration

Both files are in `C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\`.

**The idea:** run `UserDataPlugin` in the *specialize* pass (`cloudbase-init-unattend.conf`). A
rename there takes effect with the restart Sysprep does anyway, so the second restart disappears.
The service (`cloudbase-init.conf`) no longer runs it.

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
types=iso,vfat
locations=cdrom,hdd
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
types=iso,vfat
locations=cdrom,hdd
```

Notes:

- Nothing is lost by moving `UserDataPlugin`: of Proxmox's user-data only `hostname` works on
  Windows; `user`, `password`, `chpasswd`, `fqdn` and `manage_etc_hosts` are reported as *not
  supported* in the log. The password comes from the metadata (`CreateUserPlugin`,
  `SetUserPasswordPlugin`), the network from `NetworkConfigPlugin`.
- `metadata_services` with only `ConfigDriveService` keeps Cloudbase-Init from trying network
  metadata services (EC2, OpenStack HTTP), which only time out on Proxmox.
- `[config_drive] types` / `locations` replace the old `config_drive_cdrom`, `config_drive_vfat`
  and `config_drive_raw_hdd` options, which the log reports as deprecated.
- Keep `allow_reboot=false` in the unattend file: a restart there would break Sysprep.
- Your own scripts in `LocalScripts\` run at the first boot and delay everything after them: the
  log shows how long each took (`Script "…" ended with exit code`). Avoid fixed waits there.

**Status:** the cause of the second restart was read from the logs of a real Windows server
(2026-10-17). The configuration above is the recommended fix; **its effect has not been measured
yet**. After rebuilding the template, create a server and check that the System log shows a single
1074 event from `winlogon.exe` and none from Cloudbase-Init.

## Applying the configuration to a template

The files live inside the template image:

1. Create a full clone of the template (or start the VM the template was made from) and log in.
2. Edit the two files as above.
3. Generalize it again with Cloudbase-Init's answer file and let it shut down:

   ```bat
   C:\Windows\System32\Sysprep\sysprep.exe /generalize /oobe /shutdown /unattend:"C:\Program Files\Cloudbase Solutions\Cloudbase-Init\conf\Unattend.xml"
   ```

4. Convert the VM to a template in Proxmox (see [iso-templates.md](iso-templates.md#updating-a-template)).
   If it is a new VMID, sync the cluster in the panel and map the OS template to it.

## Troubleshooting

| What you see | What it means / what to do |
|---|---|
| Task step *The additional addresses could not be configured inside the server: QEMU guest agent is not running* | Windows restarted while the script ran and did not come back three times. Check the event log (1074). The scheduled task applies the addresses at the next startup; or use *Run on server* on the Scripts page |
| *Get-NetAdapter : Critical error* | Windows was still in its setup; the network cmdlets were not ready. Current scripts wait for that; update the template's override if it replaced the default |
| No scheduled task *Proxmotion addresses* | The address script never got far enough. In PowerShell: `Get-ScheduledTask -TaskName 'Proxmotion addresses'` |
| Hostname still `WIN-…` | The user-data plugin did not run, or it was not allowed to restart. Check `cloudbase-init*.log` for `Changing hostname` |
| Password not set | `Using admin_pass metadata user password` should appear in `cloudbase-init.log`; the Proxmox VM needs a CloudInit drive |

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
- Whether a template with the configuration above restarts only once (see *Status* above).
