<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Download, Plus } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import ServerStatusBadge from '@/components/admin/ServerStatusBadge.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { adopt, create, index, show } from '@/routes/admin/servers';
import type { Paginated, ServerListItem, ServerStatus } from '@/types/admin';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Servers', href: index() }] },
});

const props = defineProps<{
    servers: Paginated<ServerListItem>;
    showTerminated: boolean;
    /** Only servers with this status, e.g. the suspended ones. */
    status: ServerStatus | null;
}>();

const statusOptions = computed(() =>
    (
        [
            { value: '', label: 'All statuses' },
            { value: 'active', label: 'Active' },
            { value: 'suspended', label: 'Suspended' },
            { value: 'error', label: 'Error' },
            { value: 'pending', label: 'Pending' },
            { value: 'provisioning', label: 'Provisioning' },
            { value: 'reinstalling', label: 'Reinstalling' },
            { value: 'terminating', label: 'Deleting' },
            { value: 'terminated', label: 'Deleted' },
        ] satisfies { value: ServerStatus | ''; label: string }[]
    ).map((option) => ({ ...option, label: trans(option.label) })),
);

// A chosen status lists only its servers; "Show deleted servers" applies to all statuses.
const visit = (status: ServerStatus | null, terminated: boolean) => {
    const query: Record<string, string | number> = {};

    if (status) {
        query.status = status;
    } else if (terminated) {
        query.terminated = 1;
    }

    router.visit(index({ query }), { preserveScroll: true });
};

const statusFilter = computed({
    get: (): ServerStatus | '' => props.status ?? '',
    set: (value: ServerStatus | '') =>
        visit(value === '' ? null : value, props.showTerminated),
});

const toggleTerminated = (value: boolean | 'indeterminate') =>
    visit(null, value === true);
</script>

<template>
    <Head title="Servers" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Servers"
                description="Virtual servers managed by the panel."
            />
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" as-child>
                    <Link :href="adopt()">
                        <Download class="h-4 w-4" />
                        {{ $t('Adopt existing VM') }}
                    </Link>
                </Button>
                <Button as-child>
                    <Link :href="create()">
                        <Plus class="h-4 w-4" />
                        {{ $t('Create server') }}
                    </Link>
                </Button>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-4">
            <NativeSelect
                v-model="statusFilter"
                :options="statusOptions"
                class="w-48"
                :aria-label="$t('Status')"
                data-test="status-filter"
            />
            <!-- A chosen status already says which servers are listed. -->
            <Label v-if="!status" class="flex items-center gap-2 font-normal">
                <Checkbox
                    :model-value="showTerminated"
                    @update:model-value="toggleTerminated"
                />
                {{ $t('Show deleted servers') }}
            </Label>
        </div>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Hostname') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Status') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Billing service ID') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('IP addresses') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Package') }}
                        </th>
                        <th class="px-4 py-3 font-medium">{{ $t('Node') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="server in servers.data"
                        :key="server.uuid"
                        class="border-t hover:bg-muted/30"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="show(server.uuid)"
                                class="font-medium hover:underline"
                            >
                                {{ server.vm_name ?? server.hostname }}
                            </Link>
                            <div
                                v-if="server.vm_name"
                                class="text-xs text-muted-foreground"
                            >
                                {{ server.hostname }}
                            </div>
                            <div
                                class="font-mono text-xs text-muted-foreground"
                            >
                                VMID {{ server.vmid }}
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                <ServerStatusBadge :status="server.status" />
                                <StatusBadge
                                    v-if="server.unprotected"
                                    tone="warning"
                                    label="Anti-spoofing off"
                                />
                            </div>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">
                            {{ server.external_id ?? '—' }}
                            <div
                                v-if="server.billing_system"
                                class="font-sans text-muted-foreground"
                            >
                                {{ server.billing_system }}
                            </div>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">
                            {{ server.addresses.join(', ') || '—' }}
                        </td>
                        <td class="px-4 py-3">
                            {{ server.package ?? $t('Custom') }}
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-mono text-xs">{{
                                server.node
                            }}</span>
                            <div class="text-xs text-muted-foreground">
                                {{ server.location }}
                            </div>
                        </td>
                    </tr>
                    <tr v-if="servers.data.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            {{
                                status
                                    ? $t('No servers with this status.')
                                    : $t('No servers yet.')
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="servers.last_page > 1" class="flex flex-wrap gap-1">
            <template v-for="link in servers.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="rounded-md border px-3 py-1 text-sm"
                    :class="{
                        'bg-primary text-primary-foreground': link.active,
                    }"
                >
                    <span v-html="link.label" />
                </Link>
            </template>
        </nav>
    </div>
</template>
