<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref } from 'vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import IpPoolFormDialog from '@/components/admin/IpPoolFormDialog.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { destroy, index, show } from '@/routes/admin/ip-pools';
import { index as locationsIndex } from '@/routes/admin/locations';
import { NETWORK_MODELS } from '@/types/admin';
import type { IpPool, LocationOption, Option } from '@/types/admin';

defineOptions({
    layout: { breadcrumbs: [{ title: 'IP pools', href: index() }] },
});

defineProps<{
    pools: IpPool[];
    locations: LocationOption[];
    dnsProviders: Option[];
}>();

const dialogOpen = ref(false);
const modelLabel = (value: string) =>
    NETWORK_MODELS.find((model) => model.value === value)?.label ?? value;
</script>

<template>
    <Head title="IP pools" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="IP pools"
                description="Address blocks servers get their IPs from."
            />
            <Button
                :disabled="locations.length === 0"
                @click="dialogOpen = true"
            >
                <Plus class="h-4 w-4" />
                {{ $t('Add IP pool') }}
            </Button>
        </div>

        <p
            v-if="locations.length === 0"
            class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
        >
            {{ $t('Create a location before adding an IP pool.') }}
            <Link :href="locationsIndex()" class="font-medium underline">
                {{ $t('Locations') }}
            </Link>
        </p>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ $t('Name') }}</th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Network') }}
                        </th>
                        <th class="px-4 py-3 font-medium">{{ $t('Model') }}</th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Location') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Addresses') }}
                        </th>
                        <th class="px-4 py-3 text-right font-medium">
                            {{ $t('Actions') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="pool in pools"
                        :key="pool.uuid"
                        class="border-t hover:bg-muted/30"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="show(pool.uuid)"
                                class="font-medium hover:underline"
                            >
                                {{ pool.name }}
                            </Link>
                            <StatusBadge
                                v-if="!pool.is_active"
                                tone="neutral"
                                label="Inactive"
                                class="ml-2"
                            />
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">
                            {{ pool.network }}
                            <span class="text-muted-foreground"
                                >IPv{{ pool.version }}</span
                            >
                        </td>
                        <td class="px-4 py-3">
                            {{ $t(modelLabel(pool.network_model)) }}
                        </td>
                        <td class="px-4 py-3">{{ pool.location.name }}</td>
                        <td class="px-4 py-3">
                            {{ pool.addresses_count }}
                            <span
                                v-if="pool.assigned_count"
                                class="text-muted-foreground"
                            >
                                ({{
                                    $t(':count assigned', {
                                        count: String(pool.assigned_count),
                                    })
                                }})
                            </span>
                            <span
                                v-if="pool.reserved_count"
                                class="text-muted-foreground"
                            >
                                ({{
                                    $t(':count reserved', {
                                        count: String(pool.reserved_count),
                                    })
                                }})
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1">
                                <Button variant="outline" size="sm" as-child>
                                    <Link :href="show(pool.uuid)">
                                        {{ $t('Details') }}
                                    </Link>
                                </Button>
                                <ConfirmDelete
                                    :action="destroy(pool.uuid)"
                                    :name="pool.name"
                                    description="The pool and all of its addresses are deleted."
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="pools.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            {{ $t('No IP pools yet.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <IpPoolFormDialog
        v-model:open="dialogOpen"
        :pool="null"
        :locations="locations"
        :dns-providers="dnsProviders"
    />
</template>
