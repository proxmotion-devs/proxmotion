<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Building an OS template by hand, one tab per operating system. It explains
 * what a template must provide and why, so it works for any distribution
 * and for every Windows version from Server 2016 to Windows 11, and how to
 * check each point. Follows docs/guides/iso-templates.md and
 * docs/guides/windows-templates.md; keep them in step. Links such as
 * #template-guide-windows open a tab, #template-guide-windows-nosysprep
 * also picks the way of making Windows clones unique.
 */
type Os = 'linux' | 'windows' | 'macos';
type WindowsWay = 'sysprep' | 'nosysprep';

const tabs: { os: Os; label: string }[] = [
    { os: 'linux', label: 'Linux' },
    { os: 'windows', label: 'Windows' },
    { os: 'macos', label: 'macOS' },
];

const ways: { way: WindowsWay; label: string }[] = [
    { way: 'sysprep', label: 'With Sysprep' },
    { way: 'nosysprep', label: 'Without Sysprep' },
];

const active = ref<Os>('linux');
const windowsWay = ref<WindowsWay>('sysprep');

const openLinkedTab = () => {
    const way = ways.find(
        (item) =>
            window.location.hash === `#template-guide-windows-${item.way}`,
    );

    if (way) {
        active.value = 'windows';
        windowsWay.value = way.way;

        return;
    }

    const linked = tabs.find(
        (tab) => window.location.hash === `#template-guide-${tab.os}`,
    );

    if (linked) {
        active.value = linked.os;
    }
};

onMounted(() => {
    openLinkedTab();
    window.addEventListener('hashchange', openLinkedTab);
});
onBeforeUnmount(() => window.removeEventListener('hashchange', openLinkedTab));

// The same facts for each distribution family; the steps refer to these rows.
const familyColumns = [
    'Debian, Ubuntu',
    'AlmaLinux, Rocky, Fedora, RHEL',
    'openSUSE, SLES',
    'Arch',
];

const familyRows: { label: string; values: string[] }[] = [
    { label: 'Package tool', values: ['apt', 'dnf', 'zypper', 'pacman'] },
    {
        label: 'Package with growpart',
        values: [
            'cloud-guest-utils',
            'cloud-utils-growpart',
            'growpart',
            'cloud-guest-utils',
        ],
    },
    {
        label: 'Network files the installer leaves',
        values: [
            '/etc/netplan/ (Ubuntu), /etc/network/interfaces (Debian)',
            '/etc/NetworkManager/system-connections/',
            '/etc/sysconfig/network/ifcfg-eth0 (wicked), NetworkManager',
            '/etc/systemd/network/ or NetworkManager',
        ],
    },
    {
        label: 'Agent restrictions',
        values: [
            '—',
            '/etc/sysconfig/qemu-ga, SELinux',
            'SELinux (Leap 16)',
            '—',
        ],
    },
    {
        label: 'Default disk layout of the installer',
        values: [
            'LVM (Ubuntu), swap (Debian)',
            'LVM + swap',
            'Btrfs + swap',
            '—',
        ],
    },
];

// What differs between Windows versions; the steps refer to these rows.
// Values are shown through $t, so words among them are translated.
const windowsColumns = [
    'Server 2016',
    'Server 2019',
    'Server 2022',
    'Server 2025',
    'Windows 10',
    'Windows 11',
];

const windowsRows: { label: string; values: string[] }[] = [
    {
        label: 'Proxmox OS type',
        values: [
            '10/2016/2019',
            '10/2016/2019',
            '11/2022/2025',
            '11/2022/2025',
            '10/2016/2019',
            '11/2022/2025',
        ],
    },
    {
        label: 'VirtIO driver folder',
        values: ['2k16', '2k19', '2k22', '2k25', 'w10', 'w11'],
    },
    {
        label: 'Smallest template disk',
        values: ['32 GiB', '32 GiB', '32 GiB', '32 GiB', '32 GiB', '64 GiB'],
    },
    {
        label: 'TPM 2.0 and Secure Boot',
        values: ['No', 'No', 'No', 'No', 'No', 'Required'],
    },
    {
        label: 'Built-in Administrator',
        values: [
            'Enabled',
            'Enabled',
            'Enabled',
            'Enabled',
            'Disabled',
            'Disabled',
        ],
    },
    {
        label: 'Without Sysprep',
        values: ['Yes', 'Yes', 'Yes', 'No', 'Yes', 'No'],
    },
];

// Commands and files, shown as examples of each step.
const commands = {
    cloudDump: `qm cloudinit dump <vmid> user      # password, SSH keys, hostname
qm cloudinit dump <vmid> network   # addresses, gateway, DNS`,
    lsblk: `lsblk
# NAME   SIZE TYPE MOUNTPOINT
# sda     10G disk
# ├─sda1   1M part
# └─sda2  10G part /`,
    install: `# Debian, Ubuntu
apt update && apt full-upgrade -y
apt install -y cloud-init cloud-guest-utils qemu-guest-agent
# AlmaLinux, Rocky, Oracle, CentOS Stream, Fedora, RHEL
dnf upgrade -y
dnf install -y cloud-init cloud-utils-growpart qemu-guest-agent
# openSUSE, SLES
zypper refresh && zypper update -y
zypper install -y cloud-init growpart qemu-guest-agent
# Arch
pacman -Syu cloud-init cloud-guest-utils qemu-guest-agent`,
    cloudUnits: `systemctl list-unit-files 'cloud-*'     # enable any that are disabled`,
    cloudConfig: `cat > /etc/cloud/cloud.cfg.d/99-proxmotion.cfg <<'EOF'
datasource_list: [NoCloud, ConfigDrive, None]
disable_root: false
ssh_pwauth: true
EOF`,
    cloudFind: `ls /etc/cloud/cloud.cfg.d/ /etc/cloud/cloud-init.disabled 2>/dev/null
grep -rl -e 'config: disabled' -e 'datasource_list' /etc/cloud/
cloud-init status --long          # must not say disabled`,
    debianInterfaces: `auto lo
iface lo inet loopback

source /etc/network/interfaces.d/*`,
    agentRhel: `# RHEL family: allow guest-exec and file access
sed -i -e 's/^FILTER_RPC_ARGS=.*/FILTER_RPC_ARGS=""/' -e 's/^BLACKLIST_RPC=.*/BLACKLIST_RPC=/' /etc/sysconfig/qemu-ga
# SELinux enforcing: make only the agent's domain permissive
dnf install -y policycoreutils-python-utils
semanage permissive -a virt_qemu_ga_t`,
    agentCheck: `qm guest cmd <vmid> ping
qm guest exec <vmid> -- id
qm guest exec <vmid> -- mkdir -p /var/lib/proxmotion/check
qm guest exec <vmid> -- rmdir /var/lib/proxmotion/check /var/lib/proxmotion`,
    sshd: `printf 'PermitRootLogin yes\\nPasswordAuthentication yes\\n' > /etc/ssh/sshd_config.d/10-proxmotion.conf
sshd -T | grep -E '^(permitrootlogin|passwordauthentication) '`,
    forget: `userdel -r setup                  # the temporary user, if you made one
passwd -l root                    # cloud-init sets the real password
cloud-init clean --logs --seed
truncate -s 0 /etc/machine-id
rm -f /var/lib/dbus/machine-id && ln -s /etc/machine-id /var/lib/dbus/machine-id 2>/dev/null || true
rm -f /etc/ssh/ssh_host_* /var/lib/systemd/random-seed
rm -rf /tmp/* /var/tmp/*
journalctl --rotate && journalctl --vacuum-time=1s
apt clean 2>/dev/null; dnf clean all 2>/dev/null; zypper clean -a 2>/dev/null
fstrim -av
history -c && rm -f /root/.bash_history
poweroff`,
    windowsPrepare: `# Remote Desktop (off by default) and its firewall rules
Set-ItemProperty 'HKLM:\\System\\CurrentControlSet\\Control\\Terminal Server' -Name fDenyTSConnections -Value 0
Enable-NetFirewallRule -Group '@FirewallAPI.dll,-28752'
# Answer ping (IPv4 and IPv6)
Enable-NetFirewallRule -Name FPS-ICMP4-ERQ-In, FPS-ICMP6-ERQ-In
# No hibernation file and no Fast Startup, high-performance power plan
powercfg /hibernate off
powercfg /setactive SCHEME_MIN
# Servers: no Server Manager at logon (no such task on Windows 10/11)
Get-ScheduledTask -TaskName ServerManager -ErrorAction SilentlyContinue | Disable-ScheduledTask`,
    windowsClient: `# Device encryption off (Sysprep refuses it, clones would share the key)
manage-bde -status C:
manage-bde -off C:      # only if the volume is encrypted; wait until it is decrypted
# No promoted Store apps per user (Enterprise and Education honour it)
New-Item 'HKLM:\\SOFTWARE\\Policies\\Microsoft\\Windows\\CloudContent' -Force | Out-Null
Set-ItemProperty 'HKLM:\\SOFTWARE\\Policies\\Microsoft\\Windows\\CloudContent' -Name DisableWindowsConsumerFeatures -Value 1`,
    adminCheck: `Get-LocalUser | Where-Object { $_.SID -like '*-500' } | Format-Table Name, Enabled
# a localized name (Administrateur, Administrador…) must become Administrator
Get-LocalUser | Where-Object { $_.SID -like '*-500' } | Rename-LocalUser -NewName Administrator`,
    enableAdmin: `# C:\\Program Files\\Cloudbase Solutions\\Cloudbase-Init\\LocalScripts\\enable-administrator.ps1
# Proxmotion: the panel logs customers in as the built-in Administrator
Get-LocalUser | Where-Object { $_.SID -like '*-500' } | Enable-LocalUser`,
    clientOobeXml: `<settings pass="oobeSystem">
  <component name="Microsoft-Windows-International-Core" processorArchitecture="amd64" publicKeyToken="31bf3856ad364e35" language="neutral" versionScope="nonSxS" xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <InputLocale>en-US</InputLocale>
    <SystemLocale>en-US</SystemLocale>
    <UILanguage>en-US</UILanguage>
    <UserLocale>en-US</UserLocale>
  </component>
  <component name="Microsoft-Windows-Shell-Setup" processorArchitecture="amd64" publicKeyToken="31bf3856ad364e35" language="neutral" versionScope="nonSxS" xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <OOBE>
      <HideEULAPage>true</HideEULAPage>
      <HideOEMRegistrationScreen>true</HideOEMRegistrationScreen>
      <HideOnlineAccountScreens>true</HideOnlineAccountScreens>
      <HideWirelessSetupInOOBE>true</HideWirelessSetupInOOBE>
      <HideLocalAccountScreen>true</HideLocalAccountScreen>
      <ProtectYourPC>3</ProtectYourPC>
    </OOBE>
    <UserAccounts>
      <AdministratorPassword>
        <Value>a-long-random-value-replaced-at-first-boot</Value>
        <PlainText>true</PlainText>
      </AdministratorPassword>
    </UserAccounts>
  </component>
</settings>`,
    clientAppx: `$provisioned = (Get-AppxProvisionedPackage -Online).PackageName
Get-AppxPackage -AllUsers |
    Where-Object { -not $_.NonRemovable -and $provisioned -notcontains $_.PackageFullName } |
    ForEach-Object { Remove-AppxPackage -Package $_.PackageFullName -AllUsers -ErrorAction SilentlyContinue }`,
    win10Account: `Get-LocalUser | Where-Object { $_.SID -like '*-500' } | Enable-LocalUser
net user Administrator *          # a temporary password
# sign out, sign in as Administrator, then:
Remove-LocalUser -Name setup`,
    windowsAgentCheck: `qm guest cmd <vmid> ping
qm guest exec <vmid> -- powershell -NoProfile -Command '$PSVersionTable.PSVersion'`,
    unattend: `[DEFAULT]
username=Administrator
groups=Administrators
inject_user_password=true
first_logon_behaviour=no
metadata_services=cloudbaseinit.metadata.services.configdrive.ConfigDriveService
check_latest_version=false
log_dir=C:\\Program Files\\Cloudbase Solutions\\Cloudbase-Init\\log\\
log_file=cloudbase-init-unattend.log
plugins=cloudbaseinit.plugins.common.sethostname.SetHostNamePlugin,cloudbaseinit.plugins.windows.extendvolumes.ExtendVolumesPlugin,cloudbaseinit.plugins.common.userdata.UserDataPlugin
allow_reboot=false
stop_service_on_exit=false

[config_drive]
types=iso
locations=cdrom`,
    service: `[DEFAULT]
username=Administrator
groups=Administrators
inject_user_password=true
first_logon_behaviour=no
metadata_services=cloudbaseinit.metadata.services.configdrive.ConfigDriveService
check_latest_version=false
mtu_use_dhcp_config=false
ntp_use_dhcp_config=false
log_dir=C:\\Program Files\\Cloudbase Solutions\\Cloudbase-Init\\log\\
log_file=cloudbase-init.log
local_scripts_path=C:\\Program Files\\Cloudbase Solutions\\Cloudbase-Init\\LocalScripts\\
plugins=cloudbaseinit.plugins.common.setuserpassword.SetUserPasswordPlugin,cloudbaseinit.plugins.common.networkconfig.NetworkConfigPlugin,cloudbaseinit.plugins.common.sshpublickeys.SetUserSSHPublicKeysPlugin,cloudbaseinit.plugins.common.localscripts.LocalScriptsPlugin
allow_reboot=true
stop_service_on_exit=true

[config_drive]
types=iso
locations=cdrom`,
    serviceNoSysprep: `[DEFAULT]
username=Administrator
groups=Administrators
inject_user_password=true
first_logon_behaviour=no
metadata_services=cloudbaseinit.metadata.services.configdrive.ConfigDriveService
check_latest_version=false
mtu_use_dhcp_config=false
ntp_use_dhcp_config=false
log_dir=C:\\Program Files\\Cloudbase Solutions\\Cloudbase-Init\\log\\
log_file=cloudbase-init.log
local_scripts_path=C:\\Program Files\\Cloudbase Solutions\\Cloudbase-Init\\LocalScripts\\
plugins=cloudbaseinit.plugins.common.setuserpassword.SetUserPasswordPlugin,cloudbaseinit.plugins.common.networkconfig.NetworkConfigPlugin,cloudbaseinit.plugins.windows.extendvolumes.ExtendVolumesPlugin,cloudbaseinit.plugins.common.sshpublickeys.SetUserSSHPublicKeysPlugin,cloudbaseinit.plugins.common.localscripts.LocalScriptsPlugin,cloudbaseinit.plugins.common.userdata.UserDataPlugin
allow_reboot=true
stop_service_on_exit=true

[config_drive]
types=iso
locations=cdrom`,
    unattendXml: `<RunSynchronousCommand wcm:action="add">
  <Order>1</Order>
  <Path>cmd.exe /c ""C:\\Program Files\\Cloudbase Solutions\\Cloudbase-Init\\Python\\Scripts\\cloudbase-init.exe" --config-file "C:\\Program Files\\Cloudbase Solutions\\Cloudbase-Init\\conf\\cloudbase-init-unattend.conf" &amp; exit 0"</Path>
  <Description>Run Cloudbase-Init to set the hostname</Description>
  <WillReboot>Never</WillReboot>
</RunSynchronousCommand>`,
    generalizeXml: `<settings pass="generalize">
  <component name="Microsoft-Windows-PnpSysprep" processorArchitecture="amd64" publicKeyToken="31bf3856ad364e35" language="neutral" versionScope="nonSxS" xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <PersistAllDeviceInstalls>true</PersistAllDeviceInstalls>
  </component>
</settings>`,
    edge: `Get-AppxPackage -AllUsers *MicrosoftEdge.Stable* | Remove-AppxPackage -AllUsers`,
    windowsShrink: `# Superseded update files
Dism.exe /Online /Cleanup-Image /StartComponentCleanup /ResetBase
# Temporary files and the update download cache
Remove-Item "$env:TEMP\\*", 'C:\\Windows\\Temp\\*' -Recurse -Force -ErrorAction SilentlyContinue
Stop-Service wuauserv; Remove-Item 'C:\\Windows\\SoftwareDistribution\\Download\\*' -Recurse -Force -ErrorAction SilentlyContinue
# Tell the storage which blocks are free (needs Discard on the disk)
Optimize-Volume -DriveLetter C -ReTrim -Verbose`,
    noSysprepClean: `# Remote Desktop certificate: a new one is made on each clone
Remove-Item -Path 'Cert:\\LocalMachine\\Remote Desktop\\*' -Force
# Windows Update client ID
Stop-Service wuauserv
Remove-ItemProperty 'HKLM:\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\WindowsUpdate' -Name SusClientId, SusClientIdValidation -ErrorAction SilentlyContinue
# Cloudbase-Init's memory of instances it has seen, and its logs
Remove-Item 'HKLM:\\SOFTWARE\\Cloudbase Solutions\\Cloudbase-Init\\*' -Recurse -ErrorAction SilentlyContinue
Remove-Item 'C:\\Program Files\\Cloudbase Solutions\\Cloudbase-Init\\log\\*' -ErrorAction SilentlyContinue
# Event logs of the build
wevtutil el | ForEach-Object { wevtutil cl "$_" 2>$null }`,
    sysprep: `C:\\Windows\\System32\\Sysprep\\sysprep.exe /generalize /oobe /shutdown /unattend:"C:\\Program Files\\Cloudbase Solutions\\Cloudbase-Init\\conf\\Unattend.xml"`,
    restarts: `Get-WinEvent -FilterHashtable @{LogName='System'; Id=1074} -MaxEvents 5 | Format-List TimeCreated, Message`,
    checksum: `shasum -a 256 ~/Downloads/<file>.iso`,
};

const virtioUrl =
    'https://fedorapeople.org/groups/virt/virtio-win/direct-downloads/stable-virtio/virtio-win.iso';
const cloudbaseUrl = 'https://cloudbase.it/cloudbase-init/';
</script>

<template>
    <div
        id="template-guide"
        class="flex scroll-mt-4 flex-col gap-4 rounded-xl border p-4 text-sm"
        data-test="template-guide"
    >
        <div>
            <h3 class="text-base font-medium">
                {{ $t('How do I build a template?') }}
            </h3>
            <p class="text-muted-foreground">
                {{
                    $t(
                        'This guide explains what a template must provide and why, and how to check each point yourself, so it also works for a distribution or version it does not name. Everything happens in the Proxmox web interface, the VM console and the node shell.',
                    )
                }}
            </p>
        </div>

        <div role="tablist" class="flex flex-wrap gap-1 border-b">
            <button
                v-for="tab in tabs"
                :id="`template-guide-${tab.os}`"
                :key="tab.os"
                type="button"
                role="tab"
                :aria-selected="active === tab.os"
                class="-mb-px scroll-mt-4 border-b-2 px-3 py-2"
                :class="
                    active === tab.os
                        ? 'border-primary font-medium'
                        : 'border-transparent text-muted-foreground hover:text-foreground'
                "
                :data-test="`template-tab-${tab.os}`"
                @click="active = tab.os"
            >
                {{ $t(tab.label) }}
            </button>
        </div>

        <div
            role="tabpanel"
            class="flex flex-col gap-5 text-muted-foreground [&_code]:rounded [&_code]:bg-muted [&_code]:px-1 [&_code]:font-mono [&_code]:text-xs [&_h4]:font-medium [&_h4]:text-foreground [&_h5]:mt-1 [&_h5]:font-medium [&_h5]:text-foreground [&_ol]:list-decimal [&_ol]:space-y-1 [&_ol]:pl-5 [&_pre]:overflow-x-auto [&_pre]:rounded-md [&_pre]:bg-muted [&_pre]:p-3 [&_pre]:font-mono [&_pre]:text-xs [&_pre]:text-foreground [&_section]:flex [&_section]:flex-col [&_section]:gap-2 [&_table]:w-full [&_table]:text-xs [&_td]:border-t [&_td]:px-2 [&_td]:py-1.5 [&_td]:align-top [&_th]:px-2 [&_th]:py-1.5 [&_th]:text-left [&_th]:font-medium [&_th]:text-foreground [&_ul]:list-disc [&_ul]:space-y-1 [&_ul]:pl-5"
            :data-test="`template-panel-${active}`"
        >
            <!-- Linux -->
            <template v-if="active === 'linux'">
                <section>
                    <h4>{{ $t('How a server is born from a template') }}</h4>
                    <p>
                        {{
                            $t(
                                'Knowing what happens to a template explains every rule below. When the panel creates a server, it:',
                            )
                        }}
                    </p>
                    <ol>
                        <li>
                            {{
                                $t(
                                    'clones the template and writes the VM settings: cores, memory, the network card, and the cloud-init fields (user root, root password, SSH keys, IP addresses, DNS, the VM name as hostname);',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'grows the virtual disk to the package size: the partitions and file system inside are still the size of the template;',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'starts the VM. Proxmox hands the cloud-init fields to it on a small CD, the CloudInit drive;',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'inside the VM, cloud-init reads that CD on the first boot: it grows the root partition and file system, writes the network configuration, sets the hostname, the password and the keys, and creates new SSH host keys;',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'the panel waits for the QEMU guest agent and for cloud-init to finish, then runs its own scripts through the agent (additional addresses) and checks the server from inside.',
                                )
                            }}
                        </li>
                    </ol>
                    <p>
                        {{
                            $t(
                                'So a template is an installed system that has been made to forget who it is, and that lets cloud-init and the guest agent tell it who it is now. On the node you can always see what a VM will receive:',
                            )
                        }}
                    </p>
                    <pre>{{ commands.cloudDump }}</pre>
                </section>

                <section>
                    <h4>{{ $t('Distribution families') }}</h4>
                    <p>
                        {{
                            $t(
                                'Distributions inherit their tools from a few families; the steps below refer to these rows. When a package is not found, search the package index of your distribution for the program (growpart, cloud-init, qemu-ga).',
                            )
                        }}
                    </p>
                    <div class="overflow-x-auto">
                        <table>
                            <thead>
                                <tr>
                                    <th></th>
                                    <th
                                        v-for="column in familyColumns"
                                        :key="column"
                                    >
                                        {{ column }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in familyRows" :key="row.label">
                                    <th>{{ $t(row.label) }}</th>
                                    <td
                                        v-for="(value, index) in row.values"
                                        :key="index"
                                    >
                                        <code v-if="value !== '—'">{{
                                            value
                                        }}</code>
                                        <template v-else>—</template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section>
                    <h4>{{ $t('1. Create the VM') }}</h4>
                    <p>
                        {{
                            $t(
                                'Upload the ISO under the node, a storage with the ISO image content type, ISO Images (Download from URL with the checksum of the vendor saves a trip through your computer). Then Create VM with a VMID outside the range of your servers (e.g. 9000 and up). Every setting has a reason:',
                            )
                        }}
                    </p>
                    <ul>
                        <li>
                            {{
                                $t(
                                    'OS type Linux: Proxmox then writes the CloudInit drive in the NoCloud format every Linux cloud-init reads.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'QEMU Agent ticked: without it the VM has no channel for the guest agent.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'A small disk (10 GiB) on SCSI with VirtIO SCSI single, Discard and SSD emulation on: a full clone copies every used block, the panel grows the disk anyway, and Discard lets freed blocks go back to the storage.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'CPU type default, not host: host ties the template to one CPU model and clones may not start on other nodes.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    "Resource pool: the panel's pool, if its API token is limited to one, because the token can only clone what it can see. Memory and network only need to suit the installer; the panel sets each server's own.",
                                )
                            }}
                        </li>
                    </ul>
                </section>

                <section>
                    <h4>{{ $t('2. Install: the root partition last') }}</h4>
                    <p>
                        {{
                            $t(
                                'The rule: whatever the boot loader needs (a 1 MiB BIOS boot partition, or an EFI system partition with UEFI) comes first, and the root file system comes last, with no swap partition after it. Why: the panel adds free space at the end of the disk, and cloud-init can only grow the partition that touches it. LVM puts a volume in between that cloud-init does not grow reliably, so avoid it. Swap, if any, is a file inside root.',
                            )
                        }}
                    </p>
                    <ul>
                        <li>
                            {{
                                $t(
                                    'Ubuntu: Use an entire disk, untick Set up this disk as an LVM group. Create a temporary user setup and tick Install OpenSSH server.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Debian: Manual partitioning, one ext4 partition mounted at /, no swap. Set a root password, create the required user as setup; software: SSH server only.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'AlmaLinux, Rocky, RHEL, Fedora: Custom, Standard Partition, keep biosboot or /boot/efi if it adds one, then one / with all the space; delete swap. Minimal Install, a root password with Allow root SSH login with password.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'openSUSE, SLES: Guided Setup without LVM, separate home or separate swap (Btrfs is fine); open the SSH port in the summary.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Any other installer: the same rule by hand.',
                                )
                            }}
                        </li>
                    </ul>
                    <p>
                        {{
                            $t(
                                'Check after the first boot: root is the last partition and there is no swap partition.',
                            )
                        }}
                    </p>
                    <pre>{{ commands.lsblk }}</pre>
                </section>

                <section>
                    <h4>{{ $t('3. cloud-init') }}</h4>
                    <p>
                        {{
                            $t(
                                'Log in on the console as root (Ubuntu: as setup, then sudo -i), update the system and install cloud-init, growpart and the guest agent with the names of your family:',
                            )
                        }}
                    </p>
                    <pre>{{ commands.install }}</pre>
                    <p>
                        {{
                            $t(
                                'It must run at boot: cloud-init is a few systemd units, and some distributions (SUSE, Arch) install them disabled.',
                            )
                        }}
                    </p>
                    <pre>{{ commands.cloudUnits }}</pre>
                    <p>
                        {{
                            $t(
                                'Tell it where to look: by default it probes many clouds, some over the network, which only times out on Proxmox. Files in /etc/cloud/cloud.cfg.d/ are read in name order and later ones win, so the panel settings go into a late file. disable_root and ssh_pwauth belong to step 6.',
                            )
                        }}
                    </p>
                    <pre>{{ commands.cloudConfig }}</pre>
                    <p>
                        {{
                            $t(
                                'Remove what disables it: installers that use cloud-init themselves leave files that switch it or its network part off (on Ubuntu subiquity-disable-cloudinit-networking.cfg, 99-installer.cfg, 90-installer-network.cfg, cloud-init.disabled). Find them:',
                            )
                        }}
                    </p>
                    <pre>{{ commands.cloudFind }}</pre>
                </section>

                <section>
                    <h4>{{ $t('4. Hand the network to cloud-init') }}</h4>
                    <p>
                        {{
                            $t(
                                "Why: the installer wrote a configuration for the network it saw. On a clone, cloud-init writes its own for the server's address; if the installer's file stays, both claim the interface and the clone may keep the template's address or wait for DHCP. How: delete the installer's files (see the table), not the network stack. cloud-init writes its own file for the first stack it finds.",
                            )
                        }}
                    </p>
                    <ul>
                        <li>
                            {{
                                $t(
                                    'Ubuntu: delete the files in /etc/netplan/. NetworkManager: delete the profiles in /etc/NetworkManager/system-connections/. wicked: delete ifcfg-eth0. systemd-networkd: delete your .network files.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Debian: reduce /etc/network/interfaces to the loopback and the source line:',
                                )
                            }}
                        </li>
                    </ul>
                    <pre>{{ commands.debianInterfaces }}</pre>
                    <p>
                        {{
                            $t(
                                'Check on a test server: grep -i "selected renderer" /var/log/cloud-init.log names the stack cloud-init used.',
                            )
                        }}
                    </p>
                </section>

                <section>
                    <h4>{{ $t('5. The QEMU guest agent') }}</h4>
                    <p>
                        {{
                            $t(
                                'Why: the agent is the only way the panel gets into a server. It says the server has booted, sets passwords, writes files and runs scripts and apps; the panel never logs in over SSH. On many distributions the service is started by udev when its VirtIO port appears, which exists only with QEMU Guest Agent on in the Options of the VM: turn it on, stop and start the VM, then start the service.',
                            )
                        }}
                    </p>
                    <p>
                        {{
                            $t(
                                'Some distributions restrict the agent. The RHEL family blocks commands and file access in /etc/sysconfig/qemu-ga, and SELinux keeps commands the agent runs from creating directories or running installers:',
                            )
                        }}
                    </p>
                    <pre>{{ commands.agentRhel }}</pre>
                    <p>
                        {{
                            $t(
                                'Check from the node shell while the VM runs: every command must answer with exitcode 0. No answer to ping: the service or the VM option. exec refused: the RHEL filter. Permission denied: SELinux.',
                            )
                        }}
                    </p>
                    <pre>{{ commands.agentCheck }}</pre>
                </section>

                <section>
                    <h4>{{ $t('6. Root login') }}</h4>
                    <p>
                        {{
                            $t(
                                "Why: Proxmox passes the user root, so cloud-init puts the customer's password and keys on root, which is useless while SSH refuses root or passwords. Two places decide it: cloud-init (disable_root: false, ssh_pwauth: true, in the file of step 3) and sshd. sshd keeps the first value it reads and reads sshd_config.d in name order, so files of cloud-init (50-…) or cloud images (60-…) win over a 99-… file: use a low number. Without an Include line in sshd_config (RHEL 8 and older), set both in sshd_config itself.",
                            )
                        }}
                    </p>
                    <pre>{{ commands.sshd }}</pre>
                    <p>
                        {{
                            $t(
                                'A firewall inside the template (firewalld, ufw) must allow SSH or be off; the panel filters with the Proxmox firewall. For key-only logins use PermitRootLogin prohibit-password, PasswordAuthentication no and ssh_pwauth: false; customers then need an SSH key.',
                            )
                        }}
                    </p>
                </section>

                <section>
                    <h4>
                        {{ $t('7. Forget the identity, shrink, shut down') }}
                    </h4>
                    <p>
                        {{
                            $t(
                                'Everything that makes this VM this machine must go, so each clone creates its own:',
                            )
                        }}
                    </p>
                    <ul>
                        <li>
                            {{
                                $t(
                                    "The temporary user and root's password: they would work on every server until cloud-init sets the real one. On Ubuntu give root a temporary password first and log in as root.",
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'The cloud-init state: otherwise cloud-init takes the clone for the same instance and skips its work.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'The machine ID (emptied, not deleted: systemd fills an empty file at boot), the SSH host keys and the random seed: clones would share them.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Logs, temporary files, package caches, shell history and free blocks (fstrim): they only make every clone larger and slower to create.',
                                )
                            }}
                        </li>
                    </ul>
                    <pre>{{ commands.forget }}</pre>
                    <p>
                        {{
                            $t(
                                'Do not start the VM again afterwards: its first boot would give it an identity again. If you must, repeat this step.',
                            )
                        }}
                    </p>
                </section>

                <section>
                    <h4>{{ $t('8. Turn the VM into a template') }}</h4>
                    <ol>
                        <li>{{ $t('Hardware: remove the CD/DVD drive.') }}</li>
                        <li>
                            {{
                                $t(
                                    'Hardware, Add, CloudInit Drive: on the same storage as the disk. Leave the Cloud-Init tab empty; the panel fills it in for every server.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Options: Boot Order only the disk (scsi0), QEMU Guest Agent enabled.',
                                )
                            }}
                        </li>
                        <li>{{ $t('More, Convert to template.') }}</li>
                    </ol>
                    <p>
                        {{
                            $t(
                                'With local storage the template must exist on every node that hosts servers (Clone with a target node, full clone, then convert); with shared storage one is enough.',
                            )
                        }}
                    </p>
                </section>

                <section>
                    <h4>{{ $t('9. Add it to the panel and test it') }}</h4>
                    <ol>
                        <li>
                            {{
                                $t(
                                    "In Proxmox, add the template to the panel's resource pool: Datacenter, Permissions, Pools, the pool, Members, Add, Virtual Machine.",
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'On the Clusters page, open the cluster and run a sync.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'On the OS templates page the template is listed under Found on Proxmox with a suggested name, operating system and version. Check them and click Add.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Create two test servers and check each condition on them:',
                                )
                            }}
                        </li>
                    </ol>
                    <ul>
                        <li>
                            {{
                                $t(
                                    'The task ends without notes and the server page shows the guest agent (cloud-init and the agent work).',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    "SSH as root to the server's address works with the password and with a key (root login).",
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    "ip addr, hostname and /etc/resolv.conf show the panel's values (network).",
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'df -h / shows the package size, and grows after Change resources without a restart (disk layout).',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    '/etc/machine-id and the SSH host key fingerprints differ between the two servers (identity).',
                                )
                            }}
                        </li>
                    </ul>
                    <p>
                        {{
                            $t(
                                'Distributions without systemd (Alpine, Void) can be templates too, but the default scripts keep additional addresses and a changed hostname across restarts with systemd units: write your own under OS templates, Scripts, or offer them with one address only.',
                            )
                        }}
                    </p>
                </section>
            </template>

            <!-- Windows -->
            <template v-else-if="active === 'windows'">
                <p>
                    {{
                        $t(
                            'For Windows Server 2016, 2019, 2022 and 2025 and for Windows 10 and 11. The steps are the same for every version; where a version differs, the step says so.',
                        )
                    }}
                </p>

                <section>
                    <h4>
                        {{ $t('How a Windows server is born from a template') }}
                    </h4>
                    <p>
                        {{
                            $t(
                                'For a VM of OS type Microsoft Windows, Proxmox writes the cloud-init fields as a config drive: the password and an instance ID in meta_data.json, the network in network_data.json, the hostname in the user data. Cloudbase-Init, the Windows counterpart of cloud-init, applies them with plugins, and remembers per instance ID which plugins ran: a clone has a new ID, so each plugin runs once on it.',
                            )
                        }}
                    </p>
                    <p>
                        {{
                            $t(
                                'The panel waits until the guest agent answers, Windows setup reports IMAGE_STATE_COMPLETE and the cloudbase-init service has stopped; then the server is Active and its scripts run. Customers log in with Remote Desktop as Administrator, the account whose password the panel sets.',
                            )
                        }}
                    </p>
                </section>

                <section>
                    <h4>{{ $t('Windows versions') }}</h4>
                    <p>
                        {{
                            $t(
                                'What differs between versions; the steps below refer to these rows. Windows 11 also needs 4 GiB of memory and 2 cores, Server 2025 and Windows 11 a CPU type with x86-64-v2 (the default, never kvm64). Windows Home has no Remote Desktop host and cannot be used.',
                            )
                        }}
                    </p>
                    <div class="overflow-x-auto">
                        <table>
                            <thead>
                                <tr>
                                    <th></th>
                                    <th
                                        v-for="column in windowsColumns"
                                        :key="column"
                                    >
                                        {{ column }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in windowsRows" :key="row.label">
                                    <th>{{ $t(row.label) }}</th>
                                    <td
                                        v-for="(value, index) in row.values"
                                        :key="index"
                                    >
                                        {{ $t(value) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p>
                        {{
                            $t(
                                "Windows 10 and 11 are desktop systems: Microsoft's licence terms do not allow renting them through SPLA, and multi-tenant hosting needs Microsoft's QMTH program. Check your licensing before you sell them. Windows 10 support ended in October 2025.",
                            )
                        }}
                    </p>
                </section>

                <section>
                    <h4>
                        {{
                            $t('Making clones unique: with or without Sysprep')
                        }}
                    </h4>
                    <p>
                        {{
                            $t(
                                "A Windows installation carries values that should differ on every machine. Sysprep's generalize pass removes them, and each clone's first boot creates new ones in the specialize pass and OOBE, with one restart. Without Sysprep the clone starts as an exact copy, boots faster, and you remove what matters yourself.",
                            )
                        }}
                    </p>
                    <ul>
                        <li>
                            {{
                                $t(
                                    'With Sysprep: supported by Microsoft, a new machine SID, Remote Desktop certificate and KMS client ID on every clone. About 1.5 minutes from the first boot (measured on Server 2022).',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Without Sysprep: not supported by Microsoft, every server shares the machine SID, and KMS sees all clones as one client. Since the September 2025 updates, Windows Server 2025 and Windows 11 refuse NTLM and Kerberos between machines with the same SID, so this way is only for Server 2016 to 2022 and Windows 10. Not measured yet.',
                                )
                            }}
                        </li>
                    </ul>
                    <p>
                        {{
                            $t(
                                'Choose With Sysprep for anything you sell broadly and always for Windows Server 2025 and Windows 11. The panel works the same with both.',
                            )
                        }}
                    </p>
                    <div role="tablist" class="flex flex-wrap gap-2">
                        <button
                            v-for="item in ways"
                            :id="`template-guide-windows-${item.way}`"
                            :key="item.way"
                            type="button"
                            role="tab"
                            :aria-selected="windowsWay === item.way"
                            class="scroll-mt-4 rounded-md border px-3 py-1.5"
                            :class="
                                windowsWay === item.way
                                    ? 'border-primary bg-primary/5 font-medium text-foreground'
                                    : 'hover:text-foreground'
                            "
                            :data-test="`template-way-${item.way}`"
                            @click="windowsWay = item.way"
                        >
                            {{ $t(item.label) }}
                        </button>
                    </div>
                </section>

                <section>
                    <h4>{{ $t('1. Download the ISOs') }}</h4>
                    <ol>
                        <li>
                            {{
                                $t(
                                    'The Windows ISO of your version (volume licensing, evaluation or Media Creation Tool media): upload it under the node, the storage, ISO Images, Upload. For Windows 11, Enterprise or Education makes step 3 easier.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'The VirtIO drivers: Download from URL with this address.',
                                )
                            }}
                            <code class="break-all">{{ virtioUrl }}</code>
                        </li>
                    </ol>
                </section>

                <section>
                    <h4>{{ $t('2. Create the VM') }}</h4>
                    <ul>
                        <li>
                            {{
                                $t(
                                    'OS: type Microsoft Windows with the version from the table. This decides the config drive format and that the password reaches Cloudbase-Init. Tick Add additional drive for VirtIO drivers and choose virtio-win.iso.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'System: machine q35, BIOS OVMF (UEFI), Add EFI Disk with pre-enrolled keys (Secure Boot on), SCSI controller VirtIO SCSI single, and tick QEMU Agent. Windows 11 also needs Add TPM, version v2.0; every clone then shares that virtual TPM, which is harmless for a VPS.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Disks: bus SCSI, the smallest size from the table, Discard and SSD emulation on. The panel grows the disk but never shrinks it, so this is the smallest disk a package for the template can have.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'CPU: 4 cores while you build, so updates install faster (the panel sets the count of each server); type x86-64-v2-AES, not host. Memory: 4096 MiB, ballooning off. Network: model VirtIO.',
                                )
                            }}
                        </li>
                    </ul>
                </section>

                <section>
                    <h4>{{ $t('3. Install Windows') }}</h4>
                    <ol>
                        <li>
                            {{
                                $t(
                                    'Start the VM, open the Console and press a key when it asks to boot from CD or DVD.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'No disk is listed because Windows has no VirtIO SCSI driver: click Load driver and browse the VirtIO CD to vioscsi, the folder from the table, amd64. Then install Windows to the disk; it puts C: last, which lets C: grow later.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Windows Server: set a temporary Administrator password and log in.',
                                )
                            }}
                        </li>
                        <li v-if="windowsWay === 'sysprep'">
                            {{
                                $t(
                                    'Windows 10 and 11: at the first OOBE screen press Ctrl+Shift+F3. Windows restarts into audit mode as the built-in Administrator, without any account and without asking for a Microsoft account. Close the System Preparation Tool window and do every following step in audit mode; each restart returns to it until Sysprep ends it.',
                                )
                            }}
                        </li>
                        <li v-else>
                            {{
                                $t(
                                    'Windows 10: finish OOBE with a temporary local (offline) account named setup; it is removed in step 8.',
                                )
                            }}
                        </li>
                    </ol>
                </section>

                <section>
                    <h4>{{ $t('4. Prepare Windows') }}</h4>
                    <ol>
                        <li>
                            {{
                                $t(
                                    'On the VirtIO CD run virtio-win-guest-tools.exe: it installs every VirtIO driver and the QEMU guest agent.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Install all Windows updates and restart until none is pending: updates still pending in the template finish on every new server, with extra restarts.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'In PowerShell as Administrator, turn on Remote Desktop and ping, and turn off hibernation, which also turns off Fast Startup on Windows 10 and 11 (otherwise a shutdown is really a hibernation):',
                                )
                            }}
                        </li>
                    </ol>
                    <pre>{{ commands.windowsPrepare }}</pre>
                    <p>
                        {{
                            $t(
                                'Windows 10 and 11 only: device encryption must be off, because Sysprep refuses an encrypted drive, clones would share its key and the panel could not grow C:. Also stop Windows installing promoted Store apps for each user:',
                            )
                        }}
                    </p>
                    <pre>{{ commands.windowsClient }}</pre>
                    <p>
                        {{
                            $t(
                                'Check the agent from the node shell; the panel scripts need Windows PowerShell 5.1, which every version has:',
                            )
                        }}
                    </p>
                    <pre>{{ commands.windowsAgentCheck }}</pre>
                </section>

                <section>
                    <h4>{{ $t('5. The Administrator account') }}</h4>
                    <p>
                        {{
                            $t(
                                'The panel, its password reset and Cloudbase-Init use the built-in account whose SID ends in -500, under the name Administrator. Some display languages name it differently (Administrateur, Administrador): rename it, or install from English media.',
                            )
                        }}
                    </p>
                    <pre>{{ commands.adminCheck }}</pre>
                    <p>
                        {{
                            $t(
                                'Windows Server enables it; Windows 10 and 11 disable it, and Sysprep disables it again when it ends audit mode. So every template enables it on the first boot of each clone with a local script of Cloudbase-Init, saved once Cloudbase-Init is installed (step 6). It is harmless on servers:',
                            )
                        }}
                    </p>
                    <pre>{{ commands.enableAdmin }}</pre>
                </section>

                <section>
                    <h4>{{ $t('6. Install Cloudbase-Init') }}</h4>
                    <p>
                        {{
                            $t(
                                'Download and install Cloudbase-Init. In its installer use the user name Administrator and tick Run Cloudbase-Init service as LocalSystem. Then add the local script of step 5.',
                            )
                        }}
                        <code class="break-all">{{ cloudbaseUrl }}</code>
                    </p>
                    <p>
                        {{
                            $t(
                                'Its files are in C:\\Program Files\\Cloudbase Solutions\\Cloudbase-Init\\conf\\: cloudbase-init.conf is used by the service at every boot, cloudbase-init-unattend.conf and Unattend.xml only by Sysprep.',
                            )
                        }}
                    </p>
                </section>

                <!-- With Sysprep -->
                <template v-if="windowsWay === 'sysprep'">
                    <section data-test="template-way-panel-sysprep">
                        <h4>
                            {{ $t('7. Configure Cloudbase-Init for Sysprep') }}
                        </h4>
                        <p>
                            {{
                                $t(
                                    'Do not click Finish in the installer yet. The aim: the computer name and the C: extension are set during the specialize pass, whose restart Windows does anyway, so no other restart is needed; the service then only sets the password (first, so the template password never works on the network) and the network. Replace both files with the contents below.',
                                )
                            }}
                        </p>
                        <h5>cloudbase-init-unattend.conf</h5>
                        <pre>{{ commands.unattend }}</pre>
                        <h5>cloudbase-init.conf</h5>
                        <pre>{{ commands.service }}</pre>
                        <p>
                            {{
                                $t(
                                    "In Unattend.xml, Cloudbase-Init's command in the specialize pass ends with exit 1 and OnRequest, which asks Windows for an extra restart even on success. Replace it with the command below (keep &amp; as it is).",
                                )
                            }}
                        </p>
                        <h5>Unattend.xml (specialize)</h5>
                        <pre>{{ commands.unattendXml }}</pre>
                        <p>
                            {{
                                $t(
                                    'Windows 10 and 11: their OOBE asks for region, keyboard, an account and privacy settings, and nobody answers on a VPS. Replace the oobeSystem pass with the one below: the locale values skip region and keyboard (use yours, e.g. tr-TR), and AdministratorPassword lets OOBE skip account creation; Cloudbase-Init replaces it at the first boot, so use a long random value.',
                                )
                            }}
                        </p>
                        <h5>Unattend.xml (oobeSystem, Windows 10 and 11)</h5>
                        <pre>{{ commands.clientOobeXml }}</pre>
                        <p>
                            {{
                                $t(
                                    'Optional: the generalize pass below keeps the installed drivers, so the first boot does not detect every device again. Microsoft allows it only on identical hardware: use it only while every server keeps the virtual hardware of the template (disk bus, network model, machine type).',
                                )
                            }}
                        </p>
                        <h5>Unattend.xml (generalize, optional)</h5>
                        <pre>{{ commands.generalizeXml }}</pre>
                    </section>

                    <section>
                        <h4>{{ $t('8. Shrink and generalize') }}</h4>
                        <p>
                            {{
                                $t(
                                    'Sysprep refuses to generalize when a Store app was updated for one user only. On Server 2022 and 2025 that is Edge (Edge stays installed):',
                                )
                            }}
                        </p>
                        <pre>{{ commands.edge }}</pre>
                        <p>
                            {{
                                $t(
                                    'On Windows 10 and 11 many apps update themselves: remove every package that is installed but not provisioned (frameworks other apps need refuse to go, which is fine). C:\\Windows\\System32\\Sysprep\\Panther\\setuperr.log names any package that still stops Sysprep.',
                                )
                            }}
                        </p>
                        <pre>{{ commands.clientAppx }}</pre>
                        <p>
                            {{
                                $t(
                                    'Every GB still used on the template disk is copied for every new server. Remove superseded update files and temporary files, and tell the storage which blocks are free:',
                                )
                            }}
                        </p>
                        <pre>{{ commands.windowsShrink }}</pre>
                        <p>
                            {{
                                $t(
                                    'Back in the Cloudbase-Init installer, tick Run Sysprep to create a generalized image and Shutdown when Sysprep terminates, then click Finish. The VM generalizes itself and shuts down. Do not start it again.',
                                )
                            }}
                        </p>
                    </section>
                </template>

                <!-- Without Sysprep -->
                <template v-else>
                    <section data-test="template-way-panel-nosysprep">
                        <h4>
                            {{
                                $t(
                                    '7. Configure Cloudbase-Init without Sysprep',
                                )
                            }}
                        </h4>
                        <p>
                            {{
                                $t(
                                    'Leave both boxes on the last page of the installer unticked and click Finish. Without a specialize pass the service does everything on the first boot: password first, network, C: extension, and the computer name last, because Windows needs a restart to apply a name (allow_reboot=true); after it the plugins already done are skipped and the service stops. Replace cloudbase-init.conf with the contents below; the other two files are not used.',
                                )
                            }}
                        </p>
                        <h5>cloudbase-init.conf</h5>
                        <pre>{{ commands.serviceNoSysprep }}</pre>
                        <p>
                            {{
                                $t(
                                    'Keep the startup type of the cloudbase-init service Automatic, not delayed: without a setup pass the panel treats Windows as ready once the service has stopped, and a service that has not started yet looks stopped.',
                                )
                            }}
                        </p>
                    </section>

                    <section>
                        <h4>
                            {{
                                $t(
                                    '8. Remove what must differ, shrink, shut down',
                                )
                            }}
                        </h4>
                        <p>
                            {{
                                $t(
                                    'Windows 10: the template must not keep the temporary account. Enable the built-in Administrator, give it a password, sign in as Administrator and remove the other account:',
                                )
                            }}
                        </p>
                        <pre>{{ commands.win10Account }}</pre>
                        <p>
                            {{
                                $t(
                                    'Remove what Sysprep would have reset and what matters for a VPS: the Remote Desktop certificate (else every server shows the same one), the Windows Update client ID, the instances Cloudbase-Init remembers, and the build logs:',
                                )
                            }}
                        </p>
                        <pre>{{ commands.noSysprepClean }}</pre>
                        <p>
                            {{
                                $t(
                                    'Then remove superseded update files and temporary files, tell the storage which blocks are free, and shut down normally with Stop-Computer. Do not start the VM again before converting it.',
                                )
                            }}
                        </p>
                        <pre>{{ commands.windowsShrink }}</pre>
                    </section>
                </template>

                <section>
                    <h4>{{ $t('9. Turn the VM into a template') }}</h4>
                    <ol>
                        <li>
                            {{ $t('Hardware: remove both CD/DVD drives.') }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Hardware, Add, CloudInit Drive: on the same storage as the disk. Leave the Cloud-Init tab empty; the panel fills it in for every server.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Options: Boot Order only the disk (scsi0), QEMU Guest Agent enabled.',
                                )
                            }}
                        </li>
                        <li>{{ $t('More, Convert to template.') }}</li>
                    </ol>
                </section>

                <section>
                    <h4>{{ $t('10. Add it to the panel and test it') }}</h4>
                    <ol>
                        <li>
                            {{
                                $t(
                                    "In Proxmox, add the template to the panel's resource pool: Datacenter, Permissions, Pools, the pool, Members, Add, Virtual Machine.",
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'On the Clusters page, open the cluster and run a sync.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Add the OS template with the operating system Windows: the panel then treats its servers as Windows (password of Administrator, PowerShell scripts).',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Choose its clone method: a linked clone saves about 2 minutes per server but ties the servers to the template, which then cannot be deleted while they use it.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Give its packages at least the template disk size (Windows 11: also 4 GiB of memory and 2 cores), and tick the version only for apps that run on it: the bundled Windows apps were written for Windows Server.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Create two test servers: each should become Active about when the console shows the logon screen and accept a Remote Desktop login as Administrator with the password the panel shows, not the template password. Check the IP address, the computer name and that C: has the package size.',
                                )
                            }}
                        </li>
                    </ol>
                    <p>
                        {{
                            windowsWay === 'sysprep'
                                ? $t(
                                      'Restarts (event 1074 below): exactly one, by winlogon.exe. One by python.exe means UserDataPlugin is in the service file instead of the unattend file.',
                                  )
                                : $t(
                                      'Restarts (event 1074 below): exactly one, by Cloudbase-Init for the computer name. If the name stays the template one, UserDataPlugin or allow_reboot=true is missing.',
                                  )
                        }}
                    </p>
                    <pre>{{ commands.restarts }}</pre>
                </section>

                <section>
                    <h4>{{ $t('Updating a Windows template') }}</h4>
                    <template v-if="windowsWay === 'sysprep'">
                        <p>
                            {{
                                $t(
                                    'Make a full clone of the template, start it, install the updates until none is pending, run the commands of step 8 and generalize it again with this command; then convert it to a template with a new name.',
                                )
                            }}
                        </p>
                        <pre>{{ commands.sysprep }}</pre>
                    </template>
                    <p v-else>
                        {{
                            $t(
                                'Make a full clone of the template, set a temporary password in its Cloud-Init tab and start it. Install the updates until none is pending, run step 8 again (without the Windows 10 account part) and convert it to a template with a new name.',
                            )
                        }}
                    </p>
                    <p>
                        {{
                            $t(
                                'Licensing and activation (SPLA, AVMA, KMS) are your responsibility; the template contains no product key unless you add one.',
                            )
                        }}
                    </p>
                </section>
            </template>

            <!-- macOS -->
            <template v-else>
                <section>
                    <h4>{{ $t('Can I offer macOS servers?') }}</h4>
                    <p>
                        {{
                            $t(
                                "No. Apple's licence allows macOS virtual machines only on Apple hardware, and Proxmox nodes are not Apple machines. The panel therefore supports Linux and Windows templates only.",
                            )
                        }}
                    </p>
                </section>

                <section>
                    <h4>
                        {{ $t('I use a Mac. Can I build templates from it?') }}
                    </h4>
                    <p>
                        {{
                            $t(
                                "Yes. Every step of the Linux and Windows tabs happens in the Proxmox web interface and the VM's console in your browser, so your own computer does not matter. Use Download from URL so the ISO goes straight to the node instead of through your Mac.",
                            )
                        }}
                    </p>
                    <p>
                        {{
                            $t(
                                'If you upload an ISO from your Mac, check it first in Terminal and compare the result with the checksum on the download page:',
                            )
                        }}
                    </p>
                    <pre>{{ commands.checksum }}</pre>
                </section>
            </template>
        </div>
    </div>
</template>
