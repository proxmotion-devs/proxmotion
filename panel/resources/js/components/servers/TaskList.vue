<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CircleCheck, CircleDashed, CircleX, LoaderCircle } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useDateTime } from '@/composables/useDateTime';
import { fail } from '@/routes/admin/tasks';
import type { TaskProgress, TaskStatus, TaskType } from '@/types/admin';

/**
 * Tasks of one server with their steps, step messages and errors. A stuck
 * task (can_fail) can be marked as failed so the server takes operations again.
 */
defineProps<{ tasks: TaskProgress[] }>();

const { formatDateTime } = useDateTime();

const failing = ref<TaskProgress | null>(null);
const processing = ref(false);

const markFailed = () => {
    if (failing.value === null) {
        return;
    }

    router.visit(fail(failing.value.uuid), {
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => {
            processing.value = false;
            failing.value = null;
        },
    });
};

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
    reset_password: 'Reset root password',
    reinstall: 'Reinstall',
    change_resources: 'Change resources',
    guest_script: 'Run template script',
    change_primary_address: 'Change primary address',
    change_addresses: 'Update IP addresses',
    change_hostname: 'Change hostname',
    install_app: 'Install app',
    app_run: 'App action or uninstall',
    verify: 'Check the server',
    enable_anti_spoofing: 'Enable anti-spoofing',
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
    set_password: 'Set new root password',
    destroy: 'Delete VM and disks',
    release_ips: 'Release IP addresses',
    adopt: 'Adopt',
    wait_agent: 'Wait for the guest agent',
    addresses: 'Update IP addresses and firewall',
    apply: 'Apply changes',
    guest_addresses: 'Configure additional IP addresses in the server',
    guest_disk: 'Grow the file system in the server',
    guest_provisioned: "Run the template's script in the server",
    guest_hostname: 'Change the hostname in the server',
    app_upload: 'Upload the app installer to the server',
    app_start: 'Start the app installer',
    verify: 'Check addresses, gateway, DNS, hostname and disk',
};
// "1 min 52 s", from whole seconds.
const formatDuration = (seconds: number): string => {
    const total = Math.max(0, Math.round(seconds));
    const minutes = Math.floor(total / 60);
    const rest = total % 60;

    if (minutes === 0) {
        return trans(':seconds s', { seconds: String(rest) });
    }

    return rest === 0 || minutes >= 10
        ? trans(':minutes min', { minutes: String(minutes) })
        : trans(':minutes min :seconds s', {
              minutes: String(minutes),
              seconds: String(rest),
          });
};

// How long a finished step took; null for steps recorded without times.
const stepDuration = (step: TaskProgress['steps'][number] | null) => {
    if (!step?.started_at || !step.finished_at || step.status !== 'done') {
        return null;
    }

    const ms = Date.parse(step.finished_at) - Date.parse(step.started_at);

    return Number.isNaN(ms) ? null : formatDuration(ms / 1000);
};

// The remaining time rounded up to whole minutes, which is as exact as an estimate gets.
const remaining = (task: TaskProgress) => {
    const seconds = task.estimate?.remaining_seconds;

    if (
        seconds === undefined ||
        task.status === 'succeeded' ||
        task.status === 'failed'
    ) {
        return null;
    }

    return seconds < 60
        ? trans('Less than a minute left')
        : trans('About :time left', {
              time: formatDuration(Math.ceil(seconds / 60) * 60),
          });
};

const stepRows = (task: TaskProgress) => {
    const done = new Map(task.steps.map((step) => [step.key, step]));
    const keys = [
        ...(task.planned_steps ?? []),
        ...task.steps.map((step) => step.key),
    ];

    const rows = [...new Set(keys)].map((key) => ({
        key,
        step: done.get(key) ?? null,
        duration: stepDuration(done.get(key) ?? null),
        active: false,
    }));

    // One spinner: the step marked running, else the first one not done yet.
    if (task.status === 'running') {
        const active =
            rows.find((row) => row.step?.status === 'running') ??
            rows.find((row) => row.step?.status !== 'done');

        if (active) {
            active.active = true;
        }
    }

    return rows;
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
                <div class="flex items-center gap-2">
                    <span
                        v-if="remaining(task)"
                        class="text-xs text-muted-foreground"
                        data-test="task-estimate"
                        :title="
                            $t(
                                'Estimated from the last :count runs with this OS template.',
                                { count: String(task.estimate?.based_on ?? 0) },
                            )
                        "
                        >{{ remaining(task) }}</span
                    >
                    <Button
                        v-if="task.can_fail"
                        variant="outline"
                        size="sm"
                        data-test="task-mark-failed"
                        @click="failing = task"
                    >
                        <CircleX class="h-4 w-4" />
                        {{ $t('Mark as failed') }}
                    </Button>
                    <span class="text-xs text-muted-foreground">
                        {{ formatDateTime(task.created_at) }}
                    </span>
                </div>
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
                        v-else-if="row.active"
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
                                row.step?.status !== 'done' && !row.active,
                        }"
                    >
                        {{ $t(stepLabels[row.key] ?? row.key) }}
                    </span>
                    <span
                        v-if="row.duration"
                        class="text-xs text-muted-foreground tabular-nums"
                        data-test="step-duration"
                        >{{ row.duration }}</span
                    >
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

        <Dialog
            :open="failing !== null"
            @update:open="(open) => !open && (failing = null)"
        >
            <DialogContent>
                <DialogTitle>{{ $t('Mark this task as failed?') }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'Use this only when the task is stuck. Nothing is changed on Proxmox; check the server there, then start the operation again if it is still needed. A failed create, delete or reinstall leaves the server in error.',
                        )
                    }}
                </DialogDescription>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">{{ $t('Cancel') }}</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        :disabled="processing"
                        @click="markFailed"
                    >
                        <Spinner v-if="processing" />
                        {{ $t('Mark as failed') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
