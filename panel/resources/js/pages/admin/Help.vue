<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { BookOpen, Circle, CircleCheck } from '@lucide/vue';
import { computed } from 'vue';
import HelpQuestion from '@/components/admin/HelpQuestion.vue';
import Heading from '@/components/Heading.vue';
import { help } from '@/routes/admin';
import { index as apps } from '@/routes/admin/apps';
import { index as billingSystems } from '@/routes/admin/billing-systems';
import { index as clusters } from '@/routes/admin/clusters';
import { index as ipPools } from '@/routes/admin/ip-pools';
import { index as locations } from '@/routes/admin/locations';
import { index as osTemplates } from '@/routes/admin/os-templates';
import { index as packages } from '@/routes/admin/packages';
import { create as createServer } from '@/routes/admin/servers';

/**
 * Help: questions and answers for the pages administrators ask about most.
 * The OS template answers follow docs/guides/iso-templates.md; keep them in
 * step with it.
 */
defineOptions({
    layout: { breadcrumbs: [{ title: 'Help', href: help() }] },
});

const props = defineProps<{
    setup: {
        locations: boolean;
        clusters: boolean;
        ip_pools: boolean;
        os_templates: boolean;
        packages: boolean;
        billing_systems: boolean;
    };
    docsUrl: string | null;
}>();

const steps = computed(() => [
    {
        done: props.setup.locations,
        href: locations(),
        title: 'Add a location',
        detail: 'A data centre or region. Clusters, IP pools and servers belong to a location.',
    },
    {
        done: props.setup.clusters,
        href: clusters(),
        title: 'Connect a Proxmox cluster or node',
        detail: 'Enter the API host and an API token, then run Check permissions on the cluster page.',
    },
    {
        done: props.setup.ip_pools,
        href: ipPools(),
        title: 'Create an IP pool',
        detail: 'The addresses servers get, with gateway, bridge and network model.',
    },
    {
        done: props.setup.os_templates,
        href: osTemplates(),
        title: 'Add OS templates',
        detail: 'Build a template from an ISO in Proxmox, sync the cluster and add it here.',
    },
    {
        done: props.setup.packages,
        href: packages(),
        title: 'Create packages',
        detail: 'The plans you sell: CPU, memory, disk, addresses and limits.',
    },
    {
        done: props.setup.billing_systems,
        href: billingSystems(),
        title: 'Connect a billing system',
        detail: 'Create an API token for WHMCS or WISECP and install the module there.',
    },
]);

const topics = [
    ['getting-started', 'Getting started'],
    ['os-templates', 'OS templates'],
    ['apps', 'Apps'],
    ['billing', 'Billing systems'],
    ['troubleshooting', 'Troubleshooting'],
] as const;

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
    cloudbase: `metadata_services=cloudbaseinit.metadata.services.configdrive.ConfigDriveService`,
};

const virtioUrl =
    'https://fedorapeople.org/groups/virt/virtio-win/direct-downloads/stable-virtio/virtio-win.iso';
</script>

<template>
    <Head title="Help" />

    <div class="flex max-w-4xl flex-col gap-8 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Help"
                description="Questions and answers about setting up the panel. Click a question to open it."
            />
            <a
                v-if="docsUrl"
                :href="docsUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-1 text-sm text-sky-700 hover:underline dark:text-sky-400"
                data-test="docs-link"
            >
                <BookOpen class="h-4 w-4" />
                {{ $t('Full guides') }}
            </a>
        </div>

        <nav class="flex flex-wrap gap-2 text-sm">
            <a
                v-for="[id, label] in topics"
                :key="id"
                :href="`#${id}`"
                class="rounded-md border px-3 py-1 hover:bg-muted"
            >
                {{ $t(label) }}
            </a>
        </nav>

        <!-- Getting started -->
        <section id="getting-started" class="flex scroll-mt-4 flex-col gap-3">
            <Heading
                variant="small"
                title="Getting started"
                description="Set the panel up in this order. Steps that are done are ticked."
            />
            <ol class="flex flex-col gap-2">
                <li
                    v-for="(step, number) in steps"
                    :key="step.title"
                    class="flex items-start gap-3 rounded-lg border p-3 text-sm"
                    :data-test="`setup-step-${number + 1}`"
                >
                    <CircleCheck
                        v-if="step.done"
                        class="mt-0.5 h-4 w-4 shrink-0 text-green-600"
                    />
                    <Circle
                        v-else
                        class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground"
                    />
                    <div>
                        <Link
                            :href="step.href"
                            class="font-medium hover:underline"
                        >
                            {{ number + 1 }}. {{ $t(step.title) }}
                        </Link>
                        <p class="text-muted-foreground">
                            {{ $t(step.detail) }}
                        </p>
                    </div>
                </li>
            </ol>
            <p class="text-sm text-muted-foreground">
                {{
                    $t(
                        'Then create a test server yourself before customers order:',
                    )
                }}
                <Link :href="createServer()" class="underline">{{
                    $t('New server')
                }}</Link>
            </p>
        </section>

        <!-- OS templates: building one by hand from an ISO -->
        <section id="os-templates" class="flex scroll-mt-4 flex-col gap-3">
            <Heading
                variant="small"
                title="OS templates"
                description="Servers are full clones of a Proxmox VM template. You build each template once, from the installer ISO of the operating system, in the Proxmox web interface."
            />

            <HelpQuestion
                id="template-needs"
                title="What does a template need?"
            >
                <ul>
                    <li>
                        {{
                            $t(
                                'cloud-init installed and enabled: the panel sets the IP addresses, hostname, root password and SSH keys through it.',
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                'qemu-guest-agent installed and QEMU Guest Agent enabled on the VM: new servers wait for it, and password resets and apps need it.',
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                'One root partition at the end of the disk, without swap and without LVM, so the disk can be grown for each package.',
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                'Root login allowed: customers get a root password and SSH keys.',
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                'No machine ID, SSH host keys or installer network files: every clone gets its own.',
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                'A CloudInit drive, only the disk in the boot order, and no ISO attached.',
                            )
                        }}
                    </li>
                </ul>
            </HelpQuestion>

            <HelpQuestion
                id="iso-download"
                title="How do I download an ISO into Proxmox?"
            >
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
                    <li>
                        {{
                            $t(
                                'If the node has no internet access, download the ISO on your computer and use Upload instead.',
                            )
                        }}
                    </li>
                </ol>
                <p>
                    {{
                        $t(
                            'Take the server or minimal ISO of a release that is still supported, e.g. Ubuntu Server LTS, Debian netinst or AlmaLinux/Rocky Linux minimal.',
                        )
                    }}
                </p>
            </HelpQuestion>

            <HelpQuestion
                id="iso-vm"
                title="How do I create the VM for the template?"
            >
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
                    <li>{{ $t('OS: the ISO you downloaded; type Linux.') }}</li>
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
            </HelpQuestion>

            <HelpQuestion id="iso-install" title="How do I install the OS?">
                <p>
                    {{
                        $t(
                            'Start the VM and open its Console. Install a minimal system with one root partition (no swap, no LVM) and the OpenSSH server, nothing else.',
                        )
                    }}
                </p>
                <h4>Ubuntu Server</h4>
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
                <h4>Debian</h4>
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
                <h4>AlmaLinux / Rocky Linux</h4>
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
            </HelpQuestion>

            <HelpQuestion
                id="iso-prepare"
                title="How do I prepare the system for cloning?"
            >
                <p>
                    {{
                        $t(
                            'Log in on the console as root (Ubuntu: as setup, then sudo -i) and run the block for your OS.',
                        )
                    }}
                </p>
                <h4>Ubuntu / Debian</h4>
                <pre>{{ commands.debian }}</pre>
                <h4>AlmaLinux / Rocky Linux</h4>
                <pre>{{ commands.rhel }}</pre>
                <h4>{{ $t('Then on every Linux') }}</h4>
                <pre>{{ commands.common }}</pre>
                <p>
                    {{
                        $t(
                            'Ubuntu only: give root a temporary password, log out and log in again as root, so the setup user can be removed.',
                        )
                    }}
                </p>
                <pre>{{ commands.ubuntuRoot }}</pre>
                <h4>{{ $t('Finally, as root') }}</h4>
                <pre>{{ commands.finish }}</pre>
                <p>
                    {{
                        $t(
                            'For key-only logins use PermitRootLogin prohibit-password, PasswordAuthentication no and ssh_pwauth: false instead; customers then need an SSH key.',
                        )
                    }}
                </p>
            </HelpQuestion>

            <HelpQuestion
                id="iso-convert"
                title="How do I turn the VM into a template?"
            >
                <p>
                    {{
                        $t('With the VM stopped, in the Proxmox web interface:')
                    }}
                </p>
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
                            'On a cluster with local storage, the template must exist on every node that should host servers (Clone with Target node and Full clone, then convert the clone). With shared storage one template is enough.',
                        )
                    }}
                </p>
            </HelpQuestion>

            <HelpQuestion
                id="iso-panel"
                title="How do I add the template to the panel?"
            >
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
                <Link :href="osTemplates()" class="self-start underline">{{
                    $t('Open OS templates')
                }}</Link>
            </HelpQuestion>

            <HelpQuestion
                id="iso-windows"
                title="How do I build a Windows Server template?"
            >
                <ol>
                    <li>
                        {{
                            $t(
                                'Download two ISOs into Proxmox: Windows Server and the VirtIO drivers:',
                            )
                        }}
                        <code class="break-all">{{ virtioUrl }}</code>
                    </li>
                    <li>
                        {{
                            $t(
                                'Create the VM as for Linux, but: type Microsoft Windows with Add additional drive for VirtIO drivers, machine q35, BIOS OVMF (UEFI) with an EFI disk, a 40 GiB disk, 4096 MiB memory.',
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                'When no disk is listed during the installation, use Load driver and pick vioscsi from the VirtIO CD.',
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                'After the installation run virtio-win-guest-tools.exe from the VirtIO CD (drivers and guest agent) and install the Windows updates.',
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                'Install Cloudbase-Init (user Administrator, run as LocalSystem) and put this line into cloudbase-init.conf and cloudbase-init-unattend.conf:',
                            )
                        }}
                        <pre>{{ commands.cloudbase }}</pre>
                    </li>
                    <li>
                        {{
                            $t(
                                'On the last page of the Cloudbase-Init installer tick Run Sysprep and Shutdown when Sysprep terminates. Do not start the VM again.',
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                'Remove both CD drives, add a CloudInit drive, convert to template, and set the platform of the OS template to Windows in the panel.',
                            )
                        }}
                    </li>
                </ol>
                <p>
                    {{
                        $t(
                            'Licensing and activation are your responsibility. More in docs/guides/windows-templates.md.',
                        )
                    }}
                </p>
            </HelpQuestion>

            <HelpQuestion id="iso-update" title="How do I update a template?">
                <ol>
                    <li>
                        {{
                            $t(
                                'Make a full clone of the template. In its Cloud-Init tab set the user root and a temporary password, start it and log in on the console.',
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                'Install the updates, then run the Then on every Linux and Finally, as root blocks again. Clear the Cloud-Init user and password.',
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                'Convert the clone to a template with a new dated name, sync the cluster and tick it on the OS template in the panel.',
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                'Delete the old template. Existing servers are full clones and do not depend on it.',
                            )
                        }}
                    </li>
                </ol>
            </HelpQuestion>

            <HelpQuestion
                id="template-missing"
                title="Why does my template not appear in the panel?"
            >
                <p>
                    {{
                        $t(
                            "It is not in the panel's resource pool, it was not converted to a template, or the cluster has not been synced since. Fix it in Proxmox and sync the cluster again.",
                        )
                    }}
                </p>
            </HelpQuestion>

            <HelpQuestion
                id="template-agent"
                title="Why does a new server take 10 minutes to become active?"
            >
                <p>
                    {{
                        $t(
                            'The panel waits for the guest agent. Check that qemu-guest-agent is installed in the template and that QEMU Guest Agent is enabled under Options, then build the template again.',
                        )
                    }}
                </p>
            </HelpQuestion>
        </section>

        <!-- Apps -->
        <section id="apps" class="flex scroll-mt-4 flex-col gap-3">
            <Heading
                variant="small"
                title="Apps"
                description="Apps are installed inside customers' servers through the guest agent, at order time or later from the billing system's client area."
            />

            <HelpQuestion
                id="apps-bundled"
                title="Which apps come with the panel?"
            >
                <p>
                    {{
                        $t(
                            'Bundled apps come with the panel. After an update, run php artisan apps:sync to store their new versions. Deactivate the ones you do not want to offer. You can edit a bundled app in place; Back to bundled version undoes your changes.',
                        )
                    }}
                </p>
            </HelpQuestion>

            <HelpQuestion id="apps-own" title="How do I add my own app?">
                <ol>
                    <li>
                        {{
                            $t(
                                'New app: choose a slug and a name. The app starts as a draft made from a working skeleton.',
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                'General and Compatibility: description, category, the operating system families and versions, minimum resources and network models it needs.',
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                'Inputs and outputs: what the customer fills in (each with a type the panel checks) and what the script hands back, such as a URL or a password.',
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                'Scripts: install.sh and uninstall.sh. Actions: optional extra scripts, such as adding a VPN client.',
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                'Save draft. Problems are listed above the tabs and must be fixed before you can publish.',
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                'Publish, install it on a test server of your own from the server page and check the outputs. Deactivate the app while something is wrong.',
                            )
                        }}
                    </li>
                </ol>
                <Link :href="apps()" class="self-start underline">{{
                    $t('Open Apps')
                }}</Link>
            </HelpQuestion>

            <HelpQuestion
                id="apps-scripts"
                title="What rules do app scripts follow?"
            >
                <ul>
                    <li>
                        {{
                            $t(
                                'Scripts run as root: start with set -euo pipefail and make them safe to run twice.',
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                'Read inputs with pm_input KEY. Never paste inputs into commands unquoted.',
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                'Report progress with pm_step PERCENT TEXT, hand back results with pm_output KEY VALUE and pm_output_file, stop with pm_fail REASON.',
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                'Install packages with pm_pkg_install; it works on Debian, Ubuntu and RHEL-family servers.',
                            )
                        }}
                    </li>
                </ul>
            </HelpQuestion>

            <HelpQuestion id="apps-paid" title="How do paid apps work?">
                <p>
                    {{
                        $t(
                            'Paid apps can only be installed on servers with a right to them: the billing system grants it, or you grant it on the server page.',
                        )
                    }}
                </p>
            </HelpQuestion>

            <HelpQuestion
                id="apps-missing"
                title="Why is an app not offered for a server?"
            >
                <p>
                    {{
                        $t(
                            "The app is inactive, or the server's OS template family and version, its package resources or its network model do not fit what the app's Compatibility tab asks for.",
                        )
                    }}
                </p>
            </HelpQuestion>
        </section>

        <!-- Billing -->
        <section id="billing" class="flex scroll-mt-4 flex-col gap-3">
            <Heading
                variant="small"
                title="Billing systems"
                description="Customers never log in to the panel. Their billing system creates and manages servers through the API."
            />

            <HelpQuestion
                id="billing-connect"
                title="How do I connect WHMCS or WISECP?"
            >
                <ol>
                    <li>
                        {{
                            $t(
                                'Add the billing system and create an API token for it. The token is shown once.',
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                'Install the module: integrations/whmcs (WHMCS 8 and 9) or integrations/wisecp (WISECP 5). Their README files list the settings.',
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                "Enter the panel address and the token on the billing system's server, then test the connection.",
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                'Optional: add a webhook so the billing system hears about finished tasks at once.',
                            )
                        }}
                    </li>
                </ol>
                <Link :href="billingSystems()" class="self-start underline">{{
                    $t('Open Billing systems')
                }}</Link>
            </HelpQuestion>
        </section>

        <!-- Troubleshooting -->
        <section id="troubleshooting" class="flex scroll-mt-4 flex-col gap-3">
            <Heading variant="small" title="Troubleshooting" />

            <HelpQuestion
                id="trouble-permissions"
                title="Why does the cluster sync fail, or why can servers not be created?"
            >
                <p>
                    {{
                        $t(
                            'Open the cluster and run Check permissions: it lists missing and excess privileges.',
                        )
                    }}
                </p>
            </HelpQuestion>

            <HelpQuestion
                id="trouble-task"
                title="What do I do when a task never finishes?"
            >
                <p>
                    {{
                        $t(
                            'Check that the queue worker and the scheduler run. A stuck task can be ended with Mark as failed on the server page.',
                        )
                    }}
                </p>
            </HelpQuestion>

            <HelpQuestion
                id="trouble-drift"
                title="What if Proxmox differs from the panel?"
            >
                <p>
                    {{
                        $t(
                            'The drift report lists the differences. Nothing is changed automatically; decide there what to do.',
                        )
                    }}
                </p>
            </HelpQuestion>
        </section>
    </div>
</template>
