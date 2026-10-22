<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import FormField from '@/components/admin/FormField.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { optionalNumber } from '@/lib/forms';
import type { OptionalNumber } from '@/lib/forms';
import { edit, update } from '@/routes/admin/settings';
import type { PanelSettings } from '@/types/admin';

/**
 * Panel settings (docs/adr/0038). A setting never saved here follows its
 * .env variable; once saved, the value here wins. Security and deployment
 * settings stay in .env and are only shown.
 */
defineOptions({
    layout: { breadcrumbs: [{ title: 'Panel settings', href: edit() }] },
});

const props = defineProps<{
    settings: PanelSettings;
    unsaved: string[];
    environment: {
        require_admin_two_factor: boolean;
        console_proxy: boolean;
    };
}>();

const form = useForm({
    cross_cluster_moves: props.settings.cross_cluster_moves,
    move_deadline_minutes: props.settings.move_deadline_minutes,
    move_bwlimit_kib: (props.settings.move_bwlimit_kib ?? '') as OptionalNumber,
    min_vmid: props.settings.min_vmid,
    provisioning_deadline_minutes: props.settings.provisioning_deadline_minutes,
    verify_dns_name: props.settings.verify_dns_name ?? '',
    protected_networks: props.settings.protected_networks.join('\n'),
    drift_stopped_grace_minutes: props.settings.drift_stopped_grace_minutes,
    console_max_minutes: props.settings.console_max_minutes,
    console_idle_minutes: props.settings.console_idle_minutes,
    docs_url: props.settings.docs_url ?? '',
});

const submit = () =>
    form
        .transform((data) => ({
            ...data,
            move_bwlimit_kib: optionalNumber(data.move_bwlimit_kib),
        }))
        .submit(update(), { preserveScroll: true });

const sectionClass = 'flex flex-col gap-4 rounded-xl border p-4';
</script>

<template>
    <Head title="Panel settings" />

    <form class="flex flex-col gap-6 p-4" @submit.prevent="submit">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Panel settings"
                description="Settings that apply to the whole panel. They take effect at once, also for queued tasks."
            />
            <Button type="submit" :disabled="form.processing">
                <Save class="h-4 w-4" />
                {{ $t('Save settings') }}
            </Button>
        </div>

        <p
            v-if="unsaved.length > 0"
            class="rounded-md border border-dashed px-3 py-2 text-sm text-muted-foreground"
            data-test="settings-unsaved"
        >
            {{
                $t(
                    'Some of these values still come from .env or the defaults. Once you save this page, the values here are used and the .env variables are ignored.',
                )
            }}
        </p>

        <section :class="sectionClass" data-test="settings-moves">
            <Heading
                variant="small"
                title="Moving servers"
                description="Moves to another node of the same cluster are always offered."
            />
            <label class="flex items-start gap-2 text-sm">
                <Checkbox
                    id="cross_cluster_moves"
                    :model-value="form.cross_cluster_moves"
                    @update:model-value="
                        (checked) =>
                            (form.cross_cluster_moves = checked === true)
                    "
                />
                <span class="grid gap-1">
                    <span class="font-medium">{{
                        $t('Allow moves to other clusters and locations')
                    }}</span>
                    <span class="text-xs text-muted-foreground">{{
                        $t(
                            "These use Proxmox's remote migration, which Proxmox still calls experimental. Try it on a test server first. The target cluster also needs an incoming move token.",
                        )
                    }}</span>
                </span>
            </label>
            <div class="grid gap-4 sm:grid-cols-2">
                <FormField
                    label="Time limit of a move (minutes)"
                    for="move_deadline_minutes"
                    :error="form.errors.move_deadline_minutes"
                    hint="Copying disks to another cluster can take hours."
                >
                    <Input
                        id="move_deadline_minutes"
                        v-model.number="form.move_deadline_minutes"
                        type="number"
                        min="30"
                        max="10080"
                    />
                </FormField>
                <FormField
                    label="Bandwidth limit of a move (KiB/s)"
                    for="move_bwlimit_kib"
                    :error="form.errors.move_bwlimit_kib"
                    hint="Empty: Proxmox's own migration limit."
                >
                    <Input
                        id="move_bwlimit_kib"
                        v-model.number="form.move_bwlimit_kib"
                        type="number"
                        min="1"
                    />
                </FormField>
            </div>
        </section>

        <section :class="sectionClass" data-test="settings-provisioning">
            <Heading
                variant="small"
                title="Creating and reinstalling servers"
            />
            <div class="grid gap-4 sm:grid-cols-2">
                <FormField
                    label="Lowest VMID of new servers"
                    for="min_vmid"
                    :error="form.errors.min_vmid"
                    hint="0: Proxmox's next free ID. A higher value keeps new servers apart from VMs managed by hand."
                >
                    <Input
                        id="min_vmid"
                        v-model.number="form.min_vmid"
                        type="number"
                        min="0"
                    />
                </FormField>
                <FormField
                    label="Time limit of a creation or reinstall (minutes)"
                    for="provisioning_deadline_minutes"
                    :error="form.errors.provisioning_deadline_minutes"
                    hint="All waits included, e.g. Windows setup."
                >
                    <Input
                        id="provisioning_deadline_minutes"
                        v-model.number="form.provisioning_deadline_minutes"
                        type="number"
                        min="15"
                        max="1440"
                    />
                </FormField>
                <FormField
                    label="DNS name checked from inside"
                    for="verify_dns_name"
                    :error="form.errors.verify_dns_name"
                    hint="After a creation or reinstall the server checks that this name resolves. Empty: DNS is not checked."
                >
                    <Input
                        id="verify_dns_name"
                        v-model="form.verify_dns_name"
                        class="font-mono"
                        placeholder="example.com"
                        autocomplete="off"
                    />
                </FormField>
            </div>
        </section>

        <section :class="sectionClass" data-test="settings-isolation">
            <Heading
                variant="small"
                title="Protected networks"
                description="Servers of every location cannot open connections to these networks, e.g. the panel's own address. Each location can add its own; the Proxmox nodes are always protected."
            />
            <FormField
                label="Networks"
                for="protected_networks"
                :error="form.errors.protected_networks"
                hint="One address or CIDR per line. Applied to a server's firewall at its next firewall change."
            >
                <textarea
                    id="protected_networks"
                    v-model="form.protected_networks"
                    rows="4"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                    placeholder="198.51.100.0/24"
                />
            </FormField>
        </section>

        <section :class="sectionClass" data-test="settings-other">
            <Heading variant="small" title="Console, drift report and help" />
            <div class="grid gap-4 sm:grid-cols-2">
                <FormField
                    label="Longest console session (minutes)"
                    for="console_max_minutes"
                    :error="form.errors.console_max_minutes"
                >
                    <Input
                        id="console_max_minutes"
                        v-model.number="form.console_max_minutes"
                        type="number"
                        min="1"
                        max="1440"
                    />
                </FormField>
                <FormField
                    label="Idle console sessions close after (minutes)"
                    for="console_idle_minutes"
                    :error="form.errors.console_idle_minutes"
                >
                    <Input
                        id="console_idle_minutes"
                        v-model.number="form.console_idle_minutes"
                        type="number"
                        min="1"
                        max="1440"
                    />
                </FormField>
                <FormField
                    label="Report stopped servers after (minutes)"
                    for="drift_stopped_grace_minutes"
                    :error="form.errors.drift_stopped_grace_minutes"
                    hint="Customers may shut their servers down themselves, so a stopped active server is only reported after this long."
                >
                    <Input
                        id="drift_stopped_grace_minutes"
                        v-model.number="form.drift_stopped_grace_minutes"
                        type="number"
                        min="0"
                        max="10080"
                    />
                </FormField>
                <FormField
                    label="Documentation address"
                    for="docs_url"
                    :error="form.errors.docs_url"
                    hint="Where the full guides can be read, e.g. your fork. The Help page links to it."
                >
                    <Input
                        id="docs_url"
                        v-model="form.docs_url"
                        type="url"
                        placeholder="https://"
                        autocomplete="off"
                    />
                </FormField>
            </div>
        </section>

        <section :class="sectionClass" data-test="settings-environment">
            <Heading
                variant="small"
                title="Set in .env only"
                description="These protect the panel or depend on how it is installed, so they cannot be changed here."
            />
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <dt class="grid">
                        <span>{{
                            $t('Two-factor authentication for administrators')
                        }}</span>
                        <span class="font-mono text-xs text-muted-foreground"
                            >PROXMOTION_REQUIRE_ADMIN_2FA</span
                        >
                    </dt>
                    <dd>
                        <StatusBadge
                            v-if="environment.require_admin_two_factor"
                            tone="success"
                            label="Required"
                        />
                        <StatusBadge
                            v-else
                            tone="warning"
                            label="Not required"
                        />
                    </dd>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <dt class="grid">
                        <span>{{ $t('Console proxy') }}</span>
                        <span class="font-mono text-xs text-muted-foreground"
                            >CONSOLE_PROXY_SECRET</span
                        >
                    </dt>
                    <dd>
                        <StatusBadge
                            v-if="environment.console_proxy"
                            tone="success"
                            label="Set up"
                        />
                        <StatusBadge v-else tone="warning" label="Not set up" />
                    </dd>
                </div>
            </dl>
        </section>

        <div>
            <Button type="submit" :disabled="form.processing">
                <Save class="h-4 w-4" />
                {{ $t('Save settings') }}
            </Button>
        </div>
    </form>
</template>
