<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Check, EyeOff, Play, Square, TriangleAlert } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { useDateTime } from '@/composables/useDateTime';
import { formatMib } from '@/lib/format';
import { accept, applyPower, dismiss, markError } from '@/routes/admin/drifts';
import { adopt, show } from '@/routes/admin/servers';
import type { Drift, DriftType } from '@/types/admin';
import type { RouteDefinition } from '@/wayfinder';

/**
 * Open drifts with what differs and what an admin can do about it.
 */
defineProps<{
    drifts: Drift[];
    /** Show the server's hostname (off on the server page itself). */
    withServer?: boolean;
}>();

const { formatDateTime } = useDateTime();

// English keys, translated in the template.
const typeLabels: Record<DriftType, string> = {
    missing_vm: 'VM missing on Proxmox',
    node_mismatch: 'Moved to another node',
    resource_mismatch: 'Different resources',
    power_mismatch: 'Power state differs',
    unmanaged_vm: 'VM not managed by the panel',
};

const resources = (values: Drift['expected']) =>
    values
        ? `${values.cpu_cores} vCPU · ${formatMib(Number(values.memory_mib))} · ${formatMib(Number(values.disk_mib))}`
        : '—';

const post = (action: RouteDefinition<'post'>) =>
    router.visit(action, { preserveScroll: true });
</script>

<template>
    <ul class="flex flex-col divide-y rounded-xl border">
        <li
            v-for="drift in drifts"
            :key="drift.uuid"
            class="flex flex-wrap items-start justify-between gap-4 p-4"
        >
            <div class="flex min-w-0 items-start gap-3">
                <TriangleAlert
                    class="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400"
                />
                <div class="flex min-w-0 flex-col gap-1 text-sm">
                    <p class="font-medium">
                        {{ $t(typeLabels[drift.type]) }}
                        <template v-if="withServer && drift.server">
                            ·
                            <Link
                                :href="show(drift.server.uuid)"
                                class="font-mono hover:underline"
                                >{{ drift.server.hostname }}</Link
                            >
                        </template>
                    </p>

                    <p class="text-muted-foreground">
                        <template v-if="drift.type === 'missing_vm'">
                            {{
                                $t(
                                    'VM :vmid is not on the cluster any more. It may have been deleted in Proxmox.',
                                    { vmid: String(drift.vmid) },
                                )
                            }}
                        </template>
                        <template v-else-if="drift.type === 'node_mismatch'">
                            {{
                                $t(
                                    'Recorded on :expected, running on :actual.',
                                    {
                                        expected: String(drift.expected?.node),
                                        actual: String(drift.actual?.node),
                                    },
                                )
                            }}
                        </template>
                        <template
                            v-else-if="drift.type === 'resource_mismatch'"
                        >
                            {{
                                $t('Panel: :expected. Proxmox: :actual.', {
                                    expected: resources(drift.expected),
                                    actual: resources(drift.actual),
                                })
                            }}
                        </template>
                        <template
                            v-else-if="
                                drift.type === 'power_mismatch' &&
                                drift.expected?.status === 'running'
                            "
                        >
                            {{
                                $t(
                                    'The server is active but its VM is stopped.',
                                )
                            }}
                        </template>
                        <template v-else-if="drift.type === 'power_mismatch'">
                            {{
                                $t(
                                    'The server is suspended but its VM is running.',
                                )
                            }}
                        </template>
                        <template v-else>
                            {{
                                $t(
                                    'VM :vmid (:name) is in the resource pool but the panel does not manage it.',
                                    {
                                        vmid: String(drift.vmid),
                                        name: String(drift.actual?.name ?? '—'),
                                    },
                                )
                            }}
                        </template>
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ drift.cluster.name }} · VMID {{ drift.vmid }} ·
                        {{
                            $t('Since :time', {
                                time: formatDateTime(drift.first_seen_at),
                            })
                        }}
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="
                        drift.type === 'node_mismatch' ||
                        drift.type === 'resource_mismatch'
                    "
                    variant="outline"
                    size="sm"
                    @click="post(accept(drift.uuid))"
                >
                    <Check class="h-4 w-4" />
                    {{ $t('Accept Proxmox values') }}
                </Button>
                <Button
                    v-if="drift.type === 'missing_vm'"
                    variant="outline"
                    size="sm"
                    @click="post(markError(drift.uuid))"
                >
                    <TriangleAlert class="h-4 w-4" />
                    {{ $t('Mark server as failed') }}
                </Button>
                <Button
                    v-if="drift.type === 'power_mismatch'"
                    variant="outline"
                    size="sm"
                    @click="post(applyPower(drift.uuid))"
                >
                    <Play
                        v-if="drift.expected?.status === 'running'"
                        class="h-4 w-4"
                    />
                    <Square v-else class="h-4 w-4" />
                    {{
                        $t(
                            drift.expected?.status === 'running'
                                ? 'Start VM'
                                : 'Stop VM again',
                        )
                    }}
                </Button>
                <Button
                    v-if="drift.type === 'unmanaged_vm'"
                    as-child
                    variant="outline"
                    size="sm"
                >
                    <Link :href="adopt()">{{ $t('Adopt') }}</Link>
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    @click="post(dismiss(drift.uuid))"
                >
                    <EyeOff class="h-4 w-4" />
                    {{ $t('Dismiss') }}
                </Button>
            </div>
        </li>
    </ul>
</template>
