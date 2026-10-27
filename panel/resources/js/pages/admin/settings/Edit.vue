<script setup lang="ts">
import { Head, setLayoutProps, useForm } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { optionalNumber } from '@/lib/forms';
import type { OptionalNumber } from '@/lib/forms';
import { passwordExample } from '@/lib/passwordExample';
import type { PasswordCharacters } from '@/lib/passwordExample';
import { SETTINGS_CATEGORIES } from '@/lib/settingsCategories';
import type { SettingsCategoryKey } from '@/lib/settingsCategories';
import { edit, index, update } from '@/routes/admin/settings';
import type { PanelSettings } from '@/types/admin';

/**
 * One page of Panel settings (docs/adr/0038), opened from its entry under
 * Panel settings in the menu. A setting never saved here follows its .env
 * variable; once saved, the value here wins. Security and deployment
 * settings stay in .env and are only shown.
 */
const props = defineProps<{
    category: SettingsCategoryKey;
    /** The settings this page saves. */
    fields: string[];
    settings: PanelSettings;
    unsaved: string[];
    environment: {
        require_admin_two_factor: boolean;
        console_proxy: boolean;
    };
}>();

const current = computed(
    () =>
        SETTINGS_CATEGORIES.find((entry) => entry.key === props.category) ??
        SETTINGS_CATEGORIES[0],
);

setLayoutProps({
    breadcrumbs: [
        { title: 'Panel settings', href: index() },
        { title: current.value.title, href: edit(props.category) },
    ],
});

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
    traffic_reset_day: props.settings.traffic_reset_day,
    traffic_counted: props.settings.traffic_counted,
    traffic_action: props.settings.traffic_action,
    usage_cpu_percent: (props.settings.usage_cpu_percent ??
        '') as OptionalNumber,
    usage_memory_percent: (props.settings.usage_memory_percent ??
        '') as OptionalNumber,
    usage_disk_mb_s: (props.settings.usage_disk_mb_s ?? '') as OptionalNumber,
    usage_network_mbps: (props.settings.usage_network_mbps ??
        '') as OptionalNumber,
    usage_window_minutes: props.settings.usage_window_minutes,
    usage_action: props.settings.usage_action,
    password_length: props.settings.password_length,
    password_characters: props.settings.password_characters,
    password_prefix: props.settings.password_prefix ?? '',
    password_suffix: props.settings.password_suffix ?? '',
});

// Traffic quotas and usage limits (docs/adr/0039).
const countedOptions = computed(() => [
    { value: 'both', label: trans('Incoming and outgoing') },
    { value: 'out', label: trans('Outgoing only') },
    { value: 'in', label: trans('Incoming only') },
]);

const trafficActionOptions = computed(() => [
    { value: 'none', label: trans('Only record it') },
    { value: 'suspend', label: trans('Suspend the server') },
]);

const usageActionOptions = computed(() => [
    { value: 'none', label: trans('Only record it') },
    { value: 'stop', label: trans('Stop the server') },
    { value: 'suspend', label: trans('Suspend the server') },
]);

const submit = () =>
    form
        .transform((data) => {
            const values: Record<string, unknown> = {
                ...data,
                move_bwlimit_kib: optionalNumber(data.move_bwlimit_kib),
                usage_cpu_percent: optionalNumber(data.usage_cpu_percent),
                usage_memory_percent: optionalNumber(data.usage_memory_percent),
                usage_disk_mb_s: optionalNumber(data.usage_disk_mb_s),
                usage_network_mbps: optionalNumber(data.usage_network_mbps),
            };

            // Only this page's settings; the others keep their values.
            return Object.fromEntries(
                props.fields.map((field) => [field, values[field]]),
            );
        })
        .submit(update(props.category), { preserveScroll: true });

// Generated root passwords (docs/adr/0038): the format and an example of it.
const passwordCharacterOptions = computed(() => [
    { value: 'lower', label: trans('Lowercase letters and digits') },
    { value: 'mixed', label: trans('Upper and lowercase letters and digits') },
    { value: 'symbols', label: trans('Letters, digits and symbols') },
]);

const passwordExampleText = computed(() =>
    passwordExample(
        Number(form.password_length),
        form.password_characters as PasswordCharacters,
        form.password_prefix,
        form.password_suffix,
    ),
);

const sectionClass = 'flex flex-col gap-4 rounded-xl border p-4';
</script>

<template>
    <Head :title="current.title" />

    <form class="flex flex-col gap-6 p-4" @submit.prevent="submit">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="current.title"
                :description="current.description"
            />
            <Button
                v-if="fields.length > 0"
                type="submit"
                :disabled="form.processing"
            >
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

        <template v-if="category === 'servers'">
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

            <section :class="sectionClass" data-test="settings-passwords">
                <Heading
                    variant="small"
                    title="Generated passwords"
                    description="Used when the panel makes up a root password: a new server ordered without one, a reinstall, a reset without a chosen password. A password you or a billing system chose is kept as it is."
                />
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label="Random part length"
                        for="password_length"
                        :error="form.errors.password_length"
                        hint="12 to 64 characters."
                    >
                        <Input
                            id="password_length"
                            v-model.number="form.password_length"
                            type="number"
                            min="12"
                            max="64"
                        />
                    </FormField>
                    <FormField
                        label="Characters"
                        for="password_characters"
                        :error="form.errors.password_characters"
                        hint="Windows needs three kinds of characters in a password. With letters and digits only, add a symbol or a capital letter to the prefix or suffix."
                    >
                        <NativeSelect
                            id="password_characters"
                            v-model="form.password_characters"
                            :options="passwordCharacterOptions"
                        />
                    </FormField>
                    <FormField
                        label="Prefix"
                        for="password_prefix"
                        :error="form.errors.password_prefix"
                        hint="Letters, digits and ! @ # % + = . , : _ - only. Up to 32."
                    >
                        <Input
                            id="password_prefix"
                            v-model="form.password_prefix"
                            class="font-mono"
                            autocomplete="off"
                        />
                    </FormField>
                    <FormField
                        label="Suffix"
                        for="password_suffix"
                        :error="form.errors.password_suffix"
                        hint="The same characters as the prefix. Up to 32."
                    >
                        <Input
                            id="password_suffix"
                            v-model="form.password_suffix"
                            class="font-mono"
                            autocomplete="off"
                        />
                    </FormField>
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ $t('Example') }}:
                    <span
                        class="font-mono text-foreground"
                        data-test="password-example"
                        >{{ passwordExampleText ?? '—' }}</span
                    >
                </p>
            </section>

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
        </template>

        <template v-if="category === 'network'">
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
        </template>

        <template v-if="category === 'usage'">
            <section :class="sectionClass" data-test="settings-usage">
                <Heading
                    variant="small"
                    title="Traffic quotas"
                    description="Traffic is counted every 5 minutes. A server's quota comes from its package or its own resources; empty means unlimited."
                />
                <div class="grid gap-4 sm:grid-cols-3">
                    <FormField
                        label="Traffic resets on day"
                        for="traffic_reset_day"
                        :error="form.errors.traffic_reset_day"
                        hint="Of every month, at midnight in the panel's time zone (1–28)."
                    >
                        <Input
                            id="traffic_reset_day"
                            v-model.number="form.traffic_reset_day"
                            type="number"
                            min="1"
                            max="28"
                        />
                    </FormField>
                    <FormField
                        label="Traffic that counts"
                        for="traffic_counted"
                        :error="form.errors.traffic_counted"
                    >
                        <NativeSelect
                            id="traffic_counted"
                            v-model="form.traffic_counted"
                            :options="countedOptions"
                        />
                    </FormField>
                    <FormField
                        label="When a server is over its quota"
                        for="traffic_action"
                        :error="form.errors.traffic_action"
                        hint="A server suspended over its quota is unsuspended when the next month starts or its quota is raised."
                    >
                        <NativeSelect
                            id="traffic_action"
                            v-model="form.traffic_action"
                            :options="trafficActionOptions"
                        />
                    </FormField>
                </div>
            </section>

            <section :class="sectionClass" data-test="settings-limits">
                <Heading
                    variant="small"
                    title="Usage limits"
                    description="A server whose average over the time window reaches a limit is over it. Leave a limit empty to turn it off."
                />
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <FormField
                        label="CPU (%)"
                        for="usage_cpu_percent"
                        :error="form.errors.usage_cpu_percent"
                        hint="Of the server's own vCPUs."
                    >
                        <Input
                            id="usage_cpu_percent"
                            v-model.number="form.usage_cpu_percent"
                            type="number"
                            min="1"
                            max="100"
                        />
                    </FormField>
                    <FormField
                        label="Memory (%)"
                        for="usage_memory_percent"
                        :error="form.errors.usage_memory_percent"
                        hint="Without the balloon driver Proxmox reports what QEMU holds, which stays near the server's memory."
                    >
                        <Input
                            id="usage_memory_percent"
                            v-model.number="form.usage_memory_percent"
                            type="number"
                            min="1"
                            max="100"
                        />
                    </FormField>
                    <FormField
                        label="Disk I/O (MB/s)"
                        for="usage_disk_mb_s"
                        :error="form.errors.usage_disk_mb_s"
                        hint="Read and write together."
                    >
                        <Input
                            id="usage_disk_mb_s"
                            v-model.number="form.usage_disk_mb_s"
                            type="number"
                            min="1"
                        />
                    </FormField>
                    <FormField
                        label="Network (Mbps)"
                        for="usage_network_mbps"
                        :error="form.errors.usage_network_mbps"
                        hint="Incoming and outgoing together."
                    >
                        <Input
                            id="usage_network_mbps"
                            v-model.number="form.usage_network_mbps"
                            type="number"
                            min="1"
                        />
                    </FormField>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label="Time window (minutes)"
                        for="usage_window_minutes"
                        :error="form.errors.usage_window_minutes"
                        hint="How long the average must stay at or above a limit."
                    >
                        <Input
                            id="usage_window_minutes"
                            v-model.number="form.usage_window_minutes"
                            type="number"
                            min="15"
                            max="1440"
                        />
                    </FormField>
                    <FormField
                        label="When a server is over a limit"
                        for="usage_action"
                        :error="form.errors.usage_action"
                        hint="A stopped server may be started again by its customer. A suspended one stays suspended until you or the billing system unsuspend it."
                    >
                        <NativeSelect
                            id="usage_action"
                            v-model="form.usage_action"
                            :options="usageActionOptions"
                        />
                    </FormField>
                </div>
            </section>
        </template>

        <template v-if="category === 'general'">
            <section :class="sectionClass" data-test="settings-other">
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
        </template>

        <template v-if="category === 'environment'">
            <section :class="sectionClass" data-test="settings-environment">
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <dt class="grid">
                            <span>{{
                                $t(
                                    'Two-factor authentication for administrators',
                                )
                            }}</span>
                            <span
                                class="font-mono text-xs text-muted-foreground"
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
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <dt class="grid">
                            <span>{{ $t('Console proxy') }}</span>
                            <span
                                class="font-mono text-xs text-muted-foreground"
                                >CONSOLE_PROXY_SECRET</span
                            >
                        </dt>
                        <dd>
                            <StatusBadge
                                v-if="environment.console_proxy"
                                tone="success"
                                label="Set up"
                            />
                            <StatusBadge
                                v-else
                                tone="warning"
                                label="Not set up"
                            />
                        </dd>
                    </div>
                </dl>
            </section>
        </template>

        <div v-if="fields.length > 0">
            <Button type="submit" :disabled="form.processing">
                <Save class="h-4 w-4" />
                {{ $t('Save settings') }}
            </Button>
        </div>
    </form>
</template>
