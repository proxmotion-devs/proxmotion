<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { CircleCheck, CircleDashed, CircleX, LoaderCircle } from '@lucide/vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import { formatDateTime } from '@/lib/format';
import type { TaskProgress, TaskStatus, TaskType } from '@/types/admin';

/**
 * Tasks of one server with their steps. Step messages and errors are shown
 * when present; the client page leaves them out.
 */
defineProps<{ tasks: TaskProgress[] }>();

const page = usePage();

// English keys, translated in the template.
const taskTypeLabels: Record<TaskType, string> = {
    create: 'Create',
    suspend: 'Suspend',
    unsuspend: 'Unsuspend',
    terminate: 'Delete',
    adopt: 'Adopt',
    start: 'Start',
    shutdown: 'Shut down',
    stop: 'Stop',
    reboot: 'Reboot',
};
const taskStatusTone: Record<
    TaskStatus,
    'success' | 'warning' | 'danger' | 'neutral'
> = {
    queued: 'neutral',
    running: 'warning',
    succeeded: 'success',
    failed: 'danger',
};
const taskStatusLabels: Record<TaskStatus, string> = {
    queued: 'Queued',
    running: 'Running',
    succeeded: 'Succeeded',
    failed: 'Failed',
};
const stepLabels: Record<string, string> = {
    clone: 'Clone template',
    configure: 'Configure CPU, memory, network and cloud-init',
    resize: 'Grow disk',
    firewall: 'Enable anti-spoofing firewall',
    start: 'Start',
    stop: 'Stop',
    shutdown: 'Shut down',
    reboot: 'Reboot',
    destroy: 'Delete VM and disks',
    release_ips: 'Release IP addresses',
    adopt: 'Adopt',
};
const plannedSteps: Partial<Record<TaskType, string[]>> = {
    create: ['clone', 'configure', 'resize', 'firewall', 'start'],
    terminate: ['stop', 'destroy', 'release_ips'],
};

const stepRows = (task: TaskProgress) => {
    const done = new Map(task.steps.map((step) => [step.key, step]));
    const keys = [
        ...(plannedSteps[task.type] ?? []),
        ...task.steps.map((step) => step.key),
    ];

    return [...new Set(keys)].map((key) => ({
        key,
        step: done.get(key) ?? null,
    }));
};
</script>

<template>
    <div class="flex flex-col gap-4">
        <article
            v-for="task in tasks"
            :key="task.uuid"
            class="flex flex-col gap-3 rounded-xl border p-4"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <span class="font-medium">{{
                        $t(taskTypeLabels[task.type])
                    }}</span>
                    <StatusBadge
                        :tone="taskStatusTone[task.status]"
                        :label="taskStatusLabels[task.status]"
                    />
                </div>
                <span class="text-xs text-muted-foreground">
                    {{ formatDateTime(task.created_at, page.props.locale) }}
                </span>
            </div>

            <ol class="flex flex-col gap-1 text-sm">
                <li
                    v-for="row in stepRows(task)"
                    :key="row.key"
                    class="flex items-center gap-2"
                >
                    <CircleCheck
                        v-if="row.step?.status === 'done'"
                        class="h-4 w-4 text-emerald-600"
                    />
                    <LoaderCircle
                        v-else-if="task.status === 'running'"
                        class="h-4 w-4 animate-spin text-muted-foreground"
                    />
                    <CircleX
                        v-else-if="task.status === 'failed'"
                        class="h-4 w-4 text-muted-foreground/50"
                    />
                    <CircleDashed
                        v-else
                        class="h-4 w-4 text-muted-foreground/50"
                    />
                    <span
                        :class="{
                            'text-muted-foreground':
                                row.step?.status !== 'done',
                        }"
                    >
                        {{ $t(stepLabels[row.key] ?? row.key) }}
                    </span>
                    <span
                        v-if="row.step?.message"
                        class="text-xs text-muted-foreground"
                        >({{ row.step.message }})</span
                    >
                </li>
            </ol>

            <p
                v-if="task.error"
                class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm break-words text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300"
            >
                {{ task.error }}
            </p>
        </article>

        <p v-if="tasks.length === 0" class="text-sm text-muted-foreground">
            {{ $t('No tasks yet.') }}
        </p>
    </div>
</template>
