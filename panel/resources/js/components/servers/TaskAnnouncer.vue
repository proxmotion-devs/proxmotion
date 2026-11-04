<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { ref, watch } from 'vue';
import { TASK_STATUS_LABELS, TASK_TYPE_LABELS } from '@/lib/taskTypes';
import type { TaskProgress, TaskStatus } from '@/types/admin';

/**
 * Tells screen readers when one of the server's tasks starts, succeeds or
 * fails while the page polls (a polite live region, nothing visible). The
 * tasks shown when the page opens are not read out.
 */
const props = defineProps<{ tasks: TaskProgress[] }>();

const announcement = ref('');
const seen = new Map<string, TaskStatus>();
let ready = false;

watch(
    () => props.tasks.map((task) => [task.uuid, task.status] as const),
    (statuses) => {
        const changed = statuses.filter(
            ([uuid, status]) => ready && seen.get(uuid) !== status,
        );

        statuses.forEach(([uuid, status]) => seen.set(uuid, status));
        ready = true;

        if (changed.length === 0) {
            return;
        }

        announcement.value = changed
            .map(([uuid, status]) => {
                const task = props.tasks.find((item) => item.uuid === uuid);

                return trans(':task: :status.', {
                    task: trans(TASK_TYPE_LABELS[task!.type]),
                    status: trans(TASK_STATUS_LABELS[status]),
                });
            })
            .join(' ');
    },
    { immediate: true },
);
</script>

<template>
    <p
        class="sr-only"
        role="status"
        aria-live="polite"
        data-test="task-announcer"
    >
        {{ announcement }}
    </p>
</template>
