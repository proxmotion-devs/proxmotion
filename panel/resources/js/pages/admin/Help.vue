<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { BookOpen, Circle, CircleCheck } from '@lucide/vue';
import { computed } from 'vue';
import HelpQuestion from '@/components/admin/HelpQuestion.vue';
import TemplateGuide from '@/components/admin/TemplateGuide.vue';
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

            <TemplateGuide />

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
                id="iso-update"
                title="How do I update a Linux template?"
            >
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
                                'Details and Requirements: description and category, then the versions of your OS templates it runs on (ticked), the smallest server it needs in GB and its network models.',
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                'Customer form: what the customer fills in (each with a type the panel checks) and what the script hands back, such as a URL or a password.',
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                'Scripts & actions: install.sh and uninstall.sh, and optional extra scripts such as adding a VPN client. Edit as YAML shows the whole recipe as text.',
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
                                'Try the draft before publishing: mark a server without a billing system as a test server on its Apps tab, then use Trial runs → Try on a test server and check the log and outputs there. Deactivate the app while something is wrong.',
                            )
                        }}
                    </li>
                    <li>
                        {{
                            $t(
                                'A trial really changes the test server. Reset after trial on its Apps tab reinstalls it from its template, so the next trial starts clean.',
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
                            "The app is inactive, or the server's operating system and version, its package resources or its network model do not fit what the app's Compatibility tab asks for.",
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
