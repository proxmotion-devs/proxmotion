<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { BookOpen, CircleCheck, Circle } from '@lucide/vue';
import { computed } from 'vue';
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
 * Help: the setup order with what is done already, and short how-tos for
 * the pages that need the most explanation. The full guides live in docs/.
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
        detail: 'Build cloud-init templates on Proxmox, sync the cluster and map them here.',
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
</script>

<template>
    <Head title="Help" />

    <div class="flex max-w-4xl flex-col gap-8 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Help"
                description="How to set up the panel and the pages that need the most explanation."
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

        <section
            id="os-templates"
            class="flex scroll-mt-4 flex-col gap-3 text-sm"
        >
            <Heading
                variant="small"
                title="OS templates"
                description="Servers are full clones of a Proxmox VM template. The panel does not build templates; you build them once on Proxmox."
            />
            <p class="font-medium">{{ $t('A template must have:') }}</p>
            <ul class="list-disc space-y-1 pl-5 text-muted-foreground">
                <li>
                    {{
                        $t(
                            'cloud-init and a cloud-init drive: the panel sets the network, root password and SSH keys through it.',
                        )
                    }}
                </li>
                <li>
                    {{
                        $t(
                            'qemu-guest-agent installed and the agent enabled on the VM: new servers wait for it before they become active, and apps need it.',
                        )
                    }}
                </li>
                <li>
                    {{
                        $t(
                            "The template in the panel's resource pool, on every node that should host servers (with local storage) or on shared storage.",
                        )
                    }}
                </li>
            </ul>
            <p class="font-medium">{{ $t('Steps') }}</p>
            <ol class="list-decimal space-y-1 pl-5 text-muted-foreground">
                <li>
                    {{
                        $t(
                            'Prepare the images on your computer with deploy/proxmox/prepare-images.sh (Ubuntu and Debian cloud images with the guest agent added).',
                        )
                    }}
                </li>
                <li>
                    {{
                        $t(
                            'Copy them to the node and run deploy/proxmox/build-templates.sh in its root shell. It creates templates named tpl-OS-DATE.',
                        )
                    }}
                </li>
                <li>
                    {{
                        $t(
                            'Sync the cluster on the Clusters page so the panel sees the new templates.',
                        )
                    }}
                </li>
                <li>
                    {{
                        $t(
                            'On the OS templates page, templates nothing uses yet are listed with a suggested name, family and version. Add them, or use Add OS template and tick the Proxmox templates.',
                        )
                    }}
                </li>
                <li>
                    {{
                        $t(
                            'Check the family and version: apps use them to decide whether they fit a server.',
                        )
                    }}
                </li>
            </ol>
            <p class="text-muted-foreground">
                {{
                    $t(
                        'To refresh a template, build a new one next to the old one, sync, tick the new one on the OS template and delete the old one in Proxmox. Existing servers do not depend on it.',
                    )
                }}
            </p>
            <p class="text-muted-foreground">
                {{
                    $t(
                        'Guides in the repository: docs/guides/os-templates.md (scripts), docs/guides/iso-templates.md (by hand from an ISO), docs/guides/windows-templates.md (Windows and Cloudbase-Init).',
                    )
                }}
            </p>
            <Link :href="osTemplates()" class="self-start underline">{{
                $t('Open OS templates')
            }}</Link>
        </section>

        <section id="apps" class="flex scroll-mt-4 flex-col gap-3 text-sm">
            <Heading
                variant="small"
                title="Apps"
                description="Apps are installed inside customers' servers through the guest agent, at order time or later from the billing system's client area."
            />
            <p class="font-medium">{{ $t('Bundled apps') }}</p>
            <p class="text-muted-foreground">
                {{
                    $t(
                        'Bundled apps come with the panel. After an update, run php artisan apps:sync to store their new versions. Deactivate the ones you do not want to offer. You can edit a bundled app in place; Back to bundled version undoes your changes.',
                    )
                }}
            </p>
            <p class="font-medium">{{ $t('Adding your own app') }}</p>
            <ol class="list-decimal space-y-1 pl-5 text-muted-foreground">
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
            <p class="font-medium">{{ $t('Rules for scripts') }}</p>
            <ul class="list-disc space-y-1 pl-5 text-muted-foreground">
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
            <p class="text-muted-foreground">
                {{
                    $t(
                        'Paid apps can only be installed on servers with a right to them: the billing system grants it, or you grant it on the server page.',
                    )
                }}
            </p>
            <Link :href="apps()" class="self-start underline">{{
                $t('Open Apps')
            }}</Link>
        </section>

        <section id="billing" class="flex scroll-mt-4 flex-col gap-3 text-sm">
            <Heading
                variant="small"
                title="Billing systems"
                description="Customers never log in to the panel. Their billing system creates and manages servers through the API."
            />
            <ol class="list-decimal space-y-1 pl-5 text-muted-foreground">
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
        </section>

        <section
            id="troubleshooting"
            class="flex scroll-mt-4 flex-col gap-3 text-sm"
        >
            <Heading variant="small" title="Troubleshooting" />
            <dl class="flex flex-col gap-3">
                <div>
                    <dt class="font-medium">
                        {{
                            $t(
                                'The cluster sync fails or servers cannot be created',
                            )
                        }}
                    </dt>
                    <dd class="text-muted-foreground">
                        {{
                            $t(
                                'Open the cluster and run Check permissions: it lists missing and excess privileges.',
                            )
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="font-medium">
                        {{ $t('A template does not appear') }}
                    </dt>
                    <dd class="text-muted-foreground">
                        {{
                            $t(
                                "Add it to the panel's resource pool in Proxmox and sync the cluster again.",
                            )
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="font-medium">
                        {{
                            $t(
                                'A new server takes about 10 minutes to become active',
                            )
                        }}
                    </dt>
                    <dd class="text-muted-foreground">
                        {{
                            $t(
                                'The guest agent does not answer: the template lacks qemu-guest-agent or the agent option. Rebuild the template.',
                            )
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="font-medium">
                        {{ $t('A task never finishes') }}
                    </dt>
                    <dd class="text-muted-foreground">
                        {{
                            $t(
                                'Check that the queue worker and the scheduler run. A stuck task can be ended with Mark as failed on the server page.',
                            )
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="font-medium">
                        {{ $t('Proxmox differs from the panel') }}
                    </dt>
                    <dd class="text-muted-foreground">
                        {{
                            $t(
                                'The drift report lists the differences. Nothing is changed automatically; decide there what to do.',
                            )
                        }}
                    </dd>
                </div>
            </dl>
        </section>
    </div>
</template>
