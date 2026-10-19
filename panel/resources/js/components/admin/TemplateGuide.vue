<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Building an OS template by hand, one tab per operating system. Follows
 * docs/guides/iso-templates.md and docs/guides/windows-templates.md; keep
 * them in step. Links such as #template-guide-windows open a tab.
 */
type Os = 'linux' | 'windows' | 'macos';

const tabs: { os: Os; label: string }[] = [
    { os: 'linux', label: 'Linux' },
    { os: 'windows', label: 'Windows' },
    { os: 'macos', label: 'macOS' },
];

const active = ref<Os>('linux');

const openLinkedTab = () => {
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

// Commands are typed on the template VM's console exactly as shown.
const commands = {
    debian: `apt update && apt full-upgrade -y
apt install -y cloud-init cloud-guest-utils qemu-guest-agent openssh-server
systemctl start qemu-guest-agent

# Network: let cloud-init own it
rm -f /etc/netplan/*.yaml                                      # Ubuntu
rm -f /etc/cloud/cloud.cfg.d/subiquity-disable-cloudinit-networking.cfg \\
      /etc/cloud/cloud.cfg.d/99-installer.cfg \\
      /etc/cloud/cloud.cfg.d/90-installer-network.cfg \\
      /etc/cloud/cloud-init.disabled                           # Ubuntu installer leftovers
printf 'auto lo\\niface lo inet loopback\\n\\nsource /etc/network/interfaces.d/*\\n' \\
  > /etc/network/interfaces                                    # Debian only
apt clean`,
    rhel: `dnf upgrade -y
dnf install -y cloud-init cloud-utils-growpart qemu-guest-agent
# The agent blocks guest-exec and file access here by default; the panel needs them
sed -i -e 's/^FILTER_RPC_ARGS=.*/FILTER_RPC_ARGS=""/' -e 's/^BLACKLIST_RPC=.*/BLACKLIST_RPC=/' \\
  /etc/sysconfig/qemu-ga
systemctl enable qemu-guest-agent cloud-init cloud-init-local cloud-config cloud-final
rm -f /etc/NetworkManager/system-connections/*                 # installer network profile
dnf clean all`,
    common: `# cloud-init: read the Proxmox drive, keep root login and password authentication
cat > /etc/cloud/cloud.cfg.d/99-proxmotion.cfg <<'EOF'
datasource_list: [NoCloud, ConfigDrive, None]
disable_root: false
ssh_pwauth: true
EOF

# SSH: allow root to log in (with password and keys)
mkdir -p /etc/ssh/sshd_config.d
printf 'PermitRootLogin yes\\nPasswordAuthentication yes\\n' > /etc/ssh/sshd_config.d/99-proxmotion.conf

# Unique identity: regenerated on the clone's first boot
cloud-init clean --logs --seed
truncate -s 0 /etc/machine-id
rm -f /var/lib/dbus/machine-id && ln -s /etc/machine-id /var/lib/dbus/machine-id 2>/dev/null || true
rm -f /etc/ssh/ssh_host_*`,
    ubuntuRoot: `passwd root          # Ubuntu only: temporary, cloud-init replaces it on every clone
exit                 # leave sudo, then log out and log in as root on the console`,
    finish: `userdel -r setup                  # Ubuntu and Debian
passwd -l root                    # no installer password in the template
history -c && rm -f /root/.bash_history
poweroff`,
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
types=iso,vfat
locations=cdrom,hdd`,
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
plugins=cloudbaseinit.plugins.windows.createuser.CreateUserPlugin,cloudbaseinit.plugins.common.networkconfig.NetworkConfigPlugin,cloudbaseinit.plugins.common.sshpublickeys.SetUserSSHPublicKeysPlugin,cloudbaseinit.plugins.windows.extendvolumes.ExtendVolumesPlugin,cloudbaseinit.plugins.common.setuserpassword.SetUserPasswordPlugin,cloudbaseinit.plugins.common.localscripts.LocalScriptsPlugin
allow_reboot=true
stop_service_on_exit=true

[config_drive]
types=iso,vfat
locations=cdrom,hdd`,
    sysprep: `C:\\Windows\\System32\\Sysprep\\sysprep.exe /generalize /oobe /shutdown /unattend:"C:\\Program Files\\Cloudbase Solutions\\Cloudbase-Init\\conf\\Unattend.xml"`,
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
                        "One guide for every operating system. Everything happens in the Proxmox web interface and in the VM's console; pick your operating system.",
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
            class="flex flex-col gap-5 text-muted-foreground [&_code]:rounded [&_code]:bg-muted [&_code]:px-1 [&_code]:font-mono [&_code]:text-xs [&_h4]:font-medium [&_h4]:text-foreground [&_h5]:mt-1 [&_h5]:font-medium [&_h5]:text-foreground [&_ol]:list-decimal [&_ol]:space-y-1 [&_ol]:pl-5 [&_pre]:overflow-x-auto [&_pre]:rounded-md [&_pre]:bg-muted [&_pre]:p-3 [&_pre]:font-mono [&_pre]:text-xs [&_pre]:text-foreground [&_section]:flex [&_section]:flex-col [&_section]:gap-2 [&_ul]:list-disc [&_ul]:space-y-1 [&_ul]:pl-5"
            :data-test="`template-panel-${active}`"
        >
            <!-- Linux -->
            <template v-if="active === 'linux'">
                <p>
                    {{
                        $t(
                            'For Ubuntu, Debian, AlmaLinux and Rocky Linux. Other distributions work the same way if they have cloud-init and qemu-guest-agent.',
                        )
                    }}
                </p>

                <section>
                    <h4>{{ $t('1. Download the ISO') }}</h4>
                    <ol>
                        <li>
                            {{
                                $t(
                                    'In Proxmox, open the node, then the local storage (or another storage with the ISO image content type), then ISO Images.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    "Click Download from URL and paste the address of the ISO from the distribution's download page. Click Query URL to fill in the file name.",
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Paste the checksum from the same download page (usually SHA-256) into the checksum fields, so Proxmox checks the file. Then click Download.',
                                )
                            }}
                        </li>
                    </ol>
                    <p>
                        {{
                            $t(
                                'Take the server or minimal ISO of a release that is still supported, e.g. Ubuntu Server LTS, Debian netinst or AlmaLinux/Rocky Linux minimal. If the node has no internet access, download the ISO on your computer and use Upload instead.',
                            )
                        }}
                    </p>
                </section>

                <section>
                    <h4>{{ $t('2. Create the VM') }}</h4>
                    <p>
                        {{
                            $t(
                                'Click Create VM (top right). Use a VMID outside the range of your servers (e.g. 9000 and up) and a name such as tpl-almalinux-9-20261016.',
                            )
                        }}
                    </p>
                    <ul>
                        <li>
                            {{
                                $t(
                                    'General: the resource pool of the panel, if its API token is limited to one.',
                                )
                            }}
                        </li>
                        <li>
                            {{ $t('OS: the ISO you downloaded; type Linux.') }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'System: SCSI controller VirtIO SCSI single, and tick QEMU Agent. Leave the rest at the defaults.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Disks: bus SCSI, 10 GiB (the panel grows it for each package), Discard and SSD emulation on, on the storage servers will use.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'CPU: 2 cores with the default type. Avoid host, so clones run on every node.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Memory: 2048 MiB for the installation; the panel sets the real value.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Network: a bridge with internet access, model VirtIO.',
                                )
                            }}
                        </li>
                    </ul>
                </section>

                <section>
                    <h4>{{ $t('3. Install the operating system') }}</h4>
                    <p>
                        {{
                            $t(
                                'Start the VM and open its Console. Install a minimal system with one root partition (no swap, no LVM) and the OpenSSH server, nothing else.',
                            )
                        }}
                    </p>
                    <h5>Ubuntu Server</h5>
                    <ul>
                        <li>
                            {{
                                $t(
                                    'Storage: Use an entire disk, and untick Set up this disk as an LVM group.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Profile: create a temporary user named setup; it is removed later.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Tick Install OpenSSH server and skip the snaps. Reboot when asked.',
                                )
                            }}
                        </li>
                    </ul>
                    <h5>Debian</h5>
                    <ul>
                        <li>
                            {{
                                $t(
                                    'Set a root password and create the required user as setup; it is removed later.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Partitioning: Manual. One primary ext4 partition over the whole disk, mounted at /, bootable. No swap: confirm the warning.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Software: only SSH server and standard system utilities. Install GRUB to the disk.',
                                )
                            }}
                        </li>
                    </ul>
                    <h5>AlmaLinux / Rocky Linux</h5>
                    <ul>
                        <li>
                            {{
                                $t(
                                    'Installation Destination: Custom, Standard Partition, one xfs partition mounted at / with all the space. If a 1 MiB biosboot partition is asked for, create it first. No swap.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Software Selection: Minimal Install. Root Password: set one and tick Allow root SSH login with password.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Network & Host Name: switch the interface on, then Begin Installation.',
                                )
                            }}
                        </li>
                    </ul>
                    <p>
                        {{
                            $t(
                                'If the VM boots into the installer again, set the CD/DVD drive to Do not use any media under Hardware.',
                            )
                        }}
                    </p>
                </section>

                <section>
                    <h4>{{ $t('4. Prepare the system for cloning') }}</h4>
                    <p>
                        {{
                            $t(
                                'Log in on the console as root (Ubuntu: as setup, then sudo -i) and run the block for your OS.',
                            )
                        }}
                    </p>
                    <h5>Ubuntu / Debian</h5>
                    <pre>{{ commands.debian }}</pre>
                    <h5>AlmaLinux / Rocky Linux</h5>
                    <pre>{{ commands.rhel }}</pre>
                    <h5>{{ $t('Then on every Linux') }}</h5>
                    <pre>{{ commands.common }}</pre>
                    <p>
                        {{
                            $t(
                                'Ubuntu only: give root a temporary password, log out and log in again as root, so the setup user can be removed.',
                            )
                        }}
                    </p>
                    <pre>{{ commands.ubuntuRoot }}</pre>
                    <h5>{{ $t('Finally, as root') }}</h5>
                    <pre>{{ commands.finish }}</pre>
                    <p>
                        {{
                            $t(
                                'For key-only logins use PermitRootLogin prohibit-password, PasswordAuthentication no and ssh_pwauth: false instead; customers then need an SSH key.',
                            )
                        }}
                    </p>
                </section>

                <section>
                    <h4>{{ $t('5. Turn the VM into a template') }}</h4>
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
                </section>

                <section>
                    <h4>{{ $t('6. Add it to the panel') }}</h4>
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
                                    'On the OS templates page the template is listed under Found on Proxmox with a suggested name, family and version. Check them and click Add.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Create a test server: check that it gets its IP address and hostname, that the root password and SSH key work, that the disk has the package size (df -h /), and that the server page shows the guest agent.',
                                )
                            }}
                        </li>
                    </ol>
                </section>
            </template>

            <!-- Windows -->
            <template v-else-if="active === 'windows'">
                <p>
                    {{
                        $t(
                            'For Windows Server 2019, 2022 and 2025. Cloudbase-Init plays the part of cloud-init: it sets the Administrator password, the network and the computer name.',
                        )
                    }}
                </p>

                <section>
                    <h4>{{ $t('1. Download the ISOs') }}</h4>
                    <ol>
                        <li>
                            {{
                                $t(
                                    'The Windows Server ISO from Microsoft (evaluation or volume licensing): upload it under the node, the storage, ISO Images, Upload.',
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
                    <p>
                        {{
                            $t(
                                'Click Create VM (top right). Use a VMID outside the range of your servers (e.g. 9000 and up) and a name such as tpl-windows-2022-20261016.',
                            )
                        }}
                    </p>
                    <ul>
                        <li>
                            {{
                                $t(
                                    'General: the resource pool of the panel, if its API token is limited to one.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'OS: the Windows Server ISO; type Microsoft Windows with the matching version. Tick Add additional drive for VirtIO drivers and choose virtio-win.iso.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'System: machine q35, BIOS OVMF (UEFI), Add EFI Disk with pre-enrolled keys, SCSI controller VirtIO SCSI single, and tick QEMU Agent. No TPM is needed for Windows Server.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Disks: bus SCSI, 40 GiB or more, Discard and SSD emulation on.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'CPU and memory: 2 cores, 4096 MiB. Network: model VirtIO.',
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
                                    'Choose the edition you are licensed for (Desktop Experience or Core).',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'No disk is listed: click Load driver, browse the VirtIO CD to vioscsi, your Windows version, amd64, and install it. Then install Windows to the disk.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Set a temporary Administrator password and log in.',
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
                        <li>{{ $t('Install the Windows updates.') }}</li>
                        <li>
                            {{
                                $t(
                                    'Download and install Cloudbase-Init. In its installer use the user name Administrator and tick Run Cloudbase-Init service as LocalSystem. Do not click Finish yet.',
                                )
                            }}
                            <code class="break-all">{{ cloudbaseUrl }}</code>
                        </li>
                        <li>
                            {{
                                $t(
                                    'Replace both files in C:\\Program Files\\Cloudbase Solutions\\Cloudbase-Init\\conf\\ with the contents below. This setting saves a restart on every new server.',
                                )
                            }}
                        </li>
                    </ol>
                    <h5>cloudbase-init-unattend.conf</h5>
                    <pre>{{ commands.unattend }}</pre>
                    <h5>cloudbase-init.conf</h5>
                    <pre>{{ commands.service }}</pre>
                    <p>
                        {{
                            $t(
                                'Back in the Cloudbase-Init installer, tick Run Sysprep to create a generalized image and Shutdown when Sysprep terminates, then click Finish. The VM generalizes itself and shuts down. Do not start it again.',
                            )
                        }}
                    </p>
                </section>

                <section>
                    <h4>{{ $t('5. Turn the VM into a template') }}</h4>
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
                    <h4>{{ $t('6. Add it to the panel') }}</h4>
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
                                    'Add the OS template with the family windows: the panel then treats its servers as Windows (password of Administrator, PowerShell scripts).',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Create a test server: log in as Administrator with the password the panel shows, and check the IP address, the computer name and that the C: drive has the package size.',
                                )
                            }}
                        </li>
                    </ol>
                </section>

                <section>
                    <h4>{{ $t('Updating a Windows template') }}</h4>
                    <p>
                        {{
                            $t(
                                'Make a full clone of the template, start it, install the updates, and generalize it again with this command; then convert it to a template with a new name.',
                            )
                        }}
                    </p>
                    <pre>{{ commands.sysprep }}</pre>
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
