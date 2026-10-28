<script setup lang="ts">
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import HealthProblems from '@/components/admin/HealthProblems.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import { formatBytes } from '@/lib/format';
import { healthLabel, healthTone, lifeUsed } from '@/lib/storageHealth';
import { destroy } from '@/routes/admin/node-disks';
import type { NodeDisk } from '@/types/admin';

/** A node's physical disks with their SMART result and wear (docs/adr/0047). */
defineProps<{
    disks: NodeDisk[];
    /** Why the disks could not be read at the last sync. */
    error: string | null;
}>();
</script>

<template>
    <div class="flex flex-col gap-2">
        <p
            v-if="error"
            class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
        >
            {{ $t('Disks and pools could not be read: :error', { error }) }}
        </p>

        <div v-if="disks.length > 0" class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 font-medium">{{ $t('Disk') }}</th>
                        <th class="px-3 py-2 font-medium">{{ $t('Type') }}</th>
                        <th class="px-3 py-2 font-medium">{{ $t('Size') }}</th>
                        <th class="px-3 py-2 font-medium">
                            {{ $t('Used by') }}
                        </th>
                        <th class="px-3 py-2 font-medium">SMART</th>
                        <th class="px-3 py-2 font-medium">
                            {{ $t('Wearout') }}
                        </th>
                        <th class="px-3 py-2 font-medium">
                            {{ $t('Health') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="disk in disks"
                        :key="disk.id"
                        class="border-t align-top"
                        :class="{ 'opacity-60': !disk.is_present }"
                    >
                        <td class="px-3 py-2">
                            <div class="font-mono text-xs">
                                {{ disk.devpath }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{
                                    [disk.model, disk.serial]
                                        .filter(Boolean)
                                        .join(' · ') || '—'
                                }}
                            </div>
                        </td>
                        <td class="px-3 py-2">{{ disk.type ?? '—' }}</td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            {{ formatBytes(disk.size_bytes) }}
                        </td>
                        <td class="px-3 py-2 text-xs text-muted-foreground">
                            {{ disk.used ?? '—' }}
                        </td>
                        <td class="px-3 py-2 font-mono text-xs">
                            {{ disk.smart ?? '—' }}
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            <template v-if="lifeUsed(disk.wearout) !== null"
                                >{{ lifeUsed(disk.wearout) }}%</template
                            >
                            <template v-else>—</template>
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex items-center gap-1">
                                <StatusBadge
                                    :tone="healthTone[disk.health]"
                                    :label="healthLabel[disk.health]"
                                />
                                <ConfirmDelete
                                    v-if="!disk.is_present"
                                    :action="destroy(disk.id)"
                                    :name="disk.devpath"
                                    description="Only the panel forgets this disk, e.g. after it was replaced. Nothing is changed on Proxmox."
                                />
                            </div>
                            <HealthProblems :problems="disk.health_problems" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
