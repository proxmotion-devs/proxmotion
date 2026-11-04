<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Download, Plus, Search } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import ServerStatusBadge from '@/components/admin/ServerStatusBadge.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import Pagination from '@/components/admin/Pagination.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
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
    /** Text the list is searched for (hostname, IP address, billing ID, VMID). */
    search: string;
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
const searchText = ref(props.search);

const visit = (
    status: ServerStatus | null,
    terminated: boolean,
    search: string = props.search,
) => {
    const query: Record<string, string | number> = {};

    if (search.trim() !== '') {
        query.search = search.trim();
    }

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

const runSearch = () =>
    visit(props.status, props.showTerminated, searchText.value);
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
            <form
                class="flex w-full gap-2 sm:w-auto"
                role="search"
                data-test="server-search"
                @submit.prevent="runSearch"
            >
                <Input
                    v-model="searchText"
                    type="search"
                    class="min-w-0 flex-1 sm:w-96 sm:flex-none"
                    :placeholder="
                        $t('Hostname, IP address, billing ID or VMID')
                    "
                    :aria-label="$t('Search servers')"
                />
                <Button
                    type="submit"
                    variant="outline"
                    size="icon"
                    :aria-label="$t('Search')"
                >
                    <Search class="h-4 w-4" />
                </Button>
            </form>
            <NativeSelect
                v-model="statusFilter"
                :options="statusOptions"
                class="w-48"
                :aria-label="$t('Status')"
                data-test="status-filter"
            />
            <!-- A chosen status already says which servers are listed. -->
            <Label
                v-if="!status"
                class="flex min-h-9 items-center gap-2 font-normal"
            >
                <Checkbox
                    :model-value="showTerminated"
                    @update:model-value="toggleTerminated"
                />
                {{ $t('Show deleted servers') }}
            </Label>
        </div>

        <!-- Phones: one tappable row per server with what a ticket names first. -->
        <ul
            class="flex flex-col divide-y rounded-xl border text-sm sm:hidden"
            data-test="server-rows"
        >
            <li v-for="server in servers.data" :key="server.uuid">
                <Link
                    :href="show(server.uuid)"
                    class="flex flex-col gap-1 px-4 py-3 outline-none hover:bg-muted/30 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:ring-inset"
                >
                    <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="min-w-0 font-medium break-all">{{
                            server.vm_name ?? server.hostname
                        }}</span>
                        <ServerStatusBadge :status="server.status" />
                        <StatusBadge
                            v-if="server.unprotected"
                            tone="warning"
                            label="Anti-spoofing off"
                        />
                    </span>
                    <span
                        v-if="server.addresses.length || server.external_id"
                        class="flex flex-wrap gap-x-3 font-mono text-xs"
                    >
                        <span v-if="server.addresses.length">{{
                            server.addresses[0]
                        }}</span>
                        <span v-if="server.external_id"
                            ><span class="font-sans text-muted-foreground"
                                >{{ $t('Billing service ID') }}:</span
                            >
                            {{ server.external_id }}</span
                        >
                    </span>
                    <span class="text-xs text-muted-foreground">
                        {{ server.package ?? $t('Custom') }} ·
                        <span class="font-mono">{{ server.node }}</span> ·
                        {{ server.location }}
                    </span>
                </Link>
            </li>
            <li
                v-if="servers.data.length === 0"
                class="px-4 py-10 text-center text-muted-foreground"
            >
                {{
                    search
                        ? $t('No servers match the search.')
                        : status
                          ? $t('No servers with this status.')
                          : $t('No servers yet.')
                }}
            </li>
        </ul>

        <div class="hidden overflow-x-auto rounded-xl border sm:block">
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
                                search
                                    ? $t('No servers match the search.')
                                    : status
                                      ? $t('No servers with this status.')
                                      : $t('No servers yet.')
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="servers" />
    </div>
</template>
