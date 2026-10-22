<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus, RefreshCw } from '@lucide/vue';
import { ref } from 'vue';
import ClusterFormDialog from '@/components/admin/ClusterFormDialog.vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import GuideBox from '@/components/admin/GuideBox.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { useClusterSync } from '@/composables/useClusterSync';
import { Button } from '@/components/ui/button';
import { useDateTime } from '@/composables/useDateTime';
import { destroy, index, show } from '@/routes/admin/clusters';
import { index as locationsIndex } from '@/routes/admin/locations';
import type { Cluster, LocationOption } from '@/types/admin';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Clusters', href: index() }] },
});

defineProps<{ clusters: Cluster[]; locations: LocationOption[] }>();

const { formatDateTime } = useDateTime();
const dialogOpen = ref(false);
const { syncing, startSync } = useClusterSync();
</script>

<template>
    <Head title="Clusters" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Clusters"
                description="Proxmox clusters and standalone nodes connected to the panel."
            />
            <Button
                :disabled="locations.length === 0"
                @click="dialogOpen = true"
            >
                <Plus class="h-4 w-4" />
                {{ $t('Add cluster') }}
            </Button>
        </div>

        <GuideBox
            id="clusters"
            title="How to connect Proxmox"
            topic="trouble-permissions"
        >
            <ol>
                <li>
                    {{
                        $t(
                            "In Proxmox, create a resource pool for the panel's servers and a user with an API token (privilege separation off).",
                        )
                    }}
                </li>
                <li>
                    {{
                        $t(
                            'Give the user PVEVMAdmin and PVEPoolAdmin on the pool, PVEAuditor on / and PVEDatastoreUser on /storage.',
                        )
                    }}
                </li>
                <li>
                    {{
                        $t(
                            'Add the cluster here with the API host, the token ID (user@realm!name), the secret and the pool. A standalone node is added the same way.',
                        )
                    }}
                </li>
                <li>
                    {{
                        $t(
                            'Open the cluster and run Check permissions: it lists missing and excess privileges.',
                        )
                    }}
                </li>
            </ol>
        </GuideBox>

        <p
            v-if="locations.length === 0"
            class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
        >
            {{ $t('Create a location before adding a cluster.') }}
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
                            {{ $t('Location') }}
                        </th>
                        <th class="px-4 py-3 font-medium">{{ $t('Type') }}</th>
                        <th class="px-4 py-3 font-medium">{{ $t('Nodes') }}</th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Version') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Last sync') }}
                        </th>
                        <th class="px-4 py-3 text-right font-medium">
                            {{ $t('Actions') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="cluster in clusters"
                        :key="cluster.uuid"
                        class="border-t hover:bg-muted/30"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="show(cluster.uuid)"
                                class="font-medium hover:underline"
                            >
                                {{ cluster.name }}
                            </Link>
                            <div
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ cluster.hosts.join(', ') }}
                            </div>
                        </td>
                        <td class="px-4 py-3">{{ cluster.location.name }}</td>
                        <td class="px-4 py-3">
                            <StatusBadge
                                v-if="!cluster.is_active"
                                tone="neutral"
                                label="Inactive"
                            />
                            <span v-else-if="cluster.type">
                                {{
                                    $t(
                                        cluster.type === 'cluster'
                                            ? 'Cluster'
                                            : 'Standalone node',
                                    )
                                }}
                            </span>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-4 py-3">{{ cluster.nodes_count }}</td>
                        <td class="px-4 py-3">
                            {{ cluster.pve_version ?? '—' }}
                        </td>
                        <td class="max-w-xs px-4 py-3">
                            <template v-if="cluster.last_sync_error">
                                <StatusBadge
                                    tone="danger"
                                    label="Sync failed"
                                />
                                <div
                                    class="mt-1 text-xs break-words text-red-700 dark:text-red-400"
                                >
                                    {{ cluster.last_sync_error }}
                                </div>
                            </template>
                            <span v-else>
                                {{ formatDateTime(cluster.last_synced_at) }}
                            </span>
                            <div v-if="cluster.firewall_problem" class="mt-1">
                                <StatusBadge
                                    tone="warning"
                                    label="Anti-spoofing off"
                                />
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    :disabled="syncing === cluster.uuid"
                                    @click="startSync(cluster.uuid)"
                                >
                                    <RefreshCw
                                        class="h-4 w-4"
                                        :class="{
                                            'animate-spin':
                                                syncing === cluster.uuid,
                                        }"
                                    />
                                    {{ $t('Sync now') }}
                                </Button>
                                <Button variant="outline" size="sm" as-child>
                                    <Link :href="show(cluster.uuid)">
                                        {{ $t('Details') }}
                                    </Link>
                                </Button>
                                <ConfirmDelete
                                    :action="destroy(cluster.uuid)"
                                    :name="cluster.name"
                                    description="The cluster is only removed from the panel. Nothing is changed on Proxmox."
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="clusters.length === 0">
                        <td
                            colspan="7"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            {{ $t('No clusters yet.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <ClusterFormDialog
        v-model:open="dialogOpen"
        :cluster="null"
        :locations="locations"
    />
</template>
