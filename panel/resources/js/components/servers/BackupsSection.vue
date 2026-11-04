<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    Archive,
    CalendarClock,
    LoaderCircle,
    Plus,
    RotateCcw,
} from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import HostnameConfirmField from '@/components/admin/HostnameConfirmField.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import { useDateTime } from '@/composables/useDateTime';
import {
    BACKUP_SCHEDULE_LABELS,
    backupScheduleOptions,
} from '@/lib/backupSchedules';
import type { BackupSchedule } from '@/lib/backupSchedules';
import { formatBytes } from '@/lib/format';
import { confirmsHostname } from '@/lib/hostname';
import { backupSchedule as scheduleRoute } from '@/routes/admin/servers';
import {
    destroy as destroyRoute,
    restore as restoreRoute,
    store as storeRoute,
} from '@/routes/admin/servers/backups';
import type { ServerBackup, ServerBackups } from '@/types/admin';

/**
 * Backups of a server made by vzdump on a backup storage (docs/adr/0051
 * §4), next to its snapshots. Taking, restoring and deleting are tasks;
 * the list follows once they succeeded. A restore is confirmed by typing
 * the hostname, like a reinstall.
 */
const props = defineProps<{
    server: string;
    hostname: string;
    backups: ServerBackups;
    /** A backup task is queued or running. */
    busy?: boolean;
    disabled?: boolean;
    /** Backups kept after a termination (docs/adr/0051 §6): they can only be deleted. */
    terminated?: boolean;
}>();

const { formatDateTime } = useDateTime();

const items = computed(() => props.backups.items);
const full = computed(() => items.value.length >= props.backups.slots);

const takeForm = useForm({ label: '' });
const taking = ref(false);

const openTake = () => {
    takeForm.reset();
    takeForm.clearErrors();
    taking.value = true;
};

const take = () =>
    takeForm.submit(storeRoute(props.server), {
        preserveScroll: true,
        onSuccess: () => (taking.value = false),
    });

// Scheduled backups (docs/adr/0051 §6): the server's own schedule, or '' for its package's.
const schedule = computed(() => props.backups.schedule);
const scheduleForm = useForm({ schedule: '' as BackupSchedule | '' });
const scheduling = ref(false);

const scheduleOptions = computed(() => [
    {
        value: '' as BackupSchedule | '',
        label:
            schedule.value.package === null
                ? trans('No scheduled backups (no package)')
                : trans("Package's: :schedule", {
                      schedule: trans(
                          BACKUP_SCHEDULE_LABELS[schedule.value.package],
                      ),
                  }),
    },
    ...backupScheduleOptions(),
]);

const openSchedule = () => {
    scheduleForm.defaults({ schedule: schedule.value.own ?? '' });
    scheduleForm.reset();
    scheduleForm.clearErrors();
    scheduling.value = true;
};

const saveSchedule = () =>
    scheduleForm
        .transform((data) => ({
            schedule: data.schedule === '' ? null : data.schedule,
        }))
        .submit(scheduleRoute(props.server), {
            preserveScroll: true,
            onSuccess: () => (scheduling.value = false),
        });

const restoreForm = useForm({ confirm_hostname: '' });
const restoring = ref<ServerBackup | null>(null);
const restoreOpen = computed({
    get: () => restoring.value !== null,
    set: (open: boolean) => {
        if (!open) {
            restoring.value = null;
        }
    },
});

const startRestore = (backup: ServerBackup) => {
    restoreForm.reset();
    restoreForm.clearErrors();
    restoring.value = backup;
};

const restore = () => {
    const backup = restoring.value;

    if (backup !== null) {
        restoreForm.submit(
            restoreRoute({ server: props.server, backup: backup.id }),
            {
                preserveScroll: true,
                onSuccess: () => (restoring.value = null),
            },
        );
    }
};
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-xl border p-4"
        data-test="server-backups"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="flex items-center gap-2 font-medium">
                <Archive class="h-4 w-4" />
                {{ $t('Backups') }}
                <Badge
                    variant="secondary"
                    class="font-normal"
                    data-test="backup-count"
                >
                    {{ items.length }} / {{ backups.slots }}
                </Badge>
            </h2>
            <Button
                v-if="!terminated"
                size="sm"
                :disabled="
                    disabled || busy || full || backups.unavailable !== null
                "
                data-test="take-backup"
                @click="openTake"
            >
                <Plus class="h-4 w-4" />
                {{ $t('Take backup') }}
            </Button>
        </div>
        <p v-if="terminated" class="text-sm text-muted-foreground">
            {{
                $t(
                    'The server is terminated. Its backups are kept for the days set on Panel settings, then deleted; they can no longer be restored.',
                )
            }}
        </p>
        <p v-else class="text-sm text-muted-foreground">
            {{
                $t(
                    'Backups are full copies of the server on a backup storage. They survive reinstalls and are deleted with the server. Restoring one replaces the disk and deletes the snapshots; the panel then puts back the current resources, firewall, addresses, hostname and SSH keys.',
                )
            }}
        </p>
        <p
            v-if="backups.unavailable && !terminated"
            class="rounded-md border border-dashed px-3 py-2 text-sm text-muted-foreground"
            data-test="backups-unavailable"
        >
            {{ backups.unavailable }}
        </p>
        <div
            v-if="
                !terminated &&
                (!backups.unavailable || schedule.effective !== 'none')
            "
            class="flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm"
            data-test="backup-schedule"
        >
            <span class="grid">
                <span class="flex items-center gap-2 font-medium">
                    <CalendarClock class="h-4 w-4" />
                    {{ $t(BACKUP_SCHEDULE_LABELS[schedule.effective]) }}
                    <span
                        class="font-normal text-muted-foreground"
                        data-test="backup-schedule-source"
                    >
                        ({{
                            schedule.own === null
                                ? $t("package's schedule")
                                : $t("server's own schedule")
                        }})
                    </span>
                </span>
                <span
                    v-if="schedule.blocked"
                    class="text-xs text-status-warning-text"
                    data-test="backup-schedule-blocked"
                >
                    {{ schedule.blocked }}
                </span>
                <span
                    v-else-if="schedule.next_at"
                    class="text-xs text-muted-foreground"
                    data-test="backup-schedule-next"
                >
                    {{
                        $t('Next scheduled backup: :time', {
                            time: formatDateTime(schedule.next_at),
                        })
                    }}
                </span>
            </span>
            <Button
                variant="outline"
                size="sm"
                :disabled="disabled"
                data-test="change-backup-schedule"
                @click="openSchedule"
            >
                {{ $t('Change') }}
            </Button>
        </div>
        <p
            v-if="busy"
            class="flex items-center gap-2 rounded-md border border-dashed px-3 py-2 text-sm text-muted-foreground"
            data-test="backups-busy"
        >
            <LoaderCircle class="h-4 w-4 animate-spin" />
            {{
                $t(
                    'A backup task is running; the list follows once it is done.',
                )
            }}
        </p>

        <ul v-if="items.length > 0" class="grid gap-2 text-sm">
            <li
                v-for="backup in items"
                :key="backup.id"
                class="flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2"
                data-test="backup"
            >
                <span class="grid">
                    <span class="flex items-center gap-2 font-medium">
                        {{
                            backup.label ??
                            (backup.kind === 'scheduled'
                                ? $t('Scheduled backup')
                                : $t('No label'))
                        }}
                        <Badge
                            v-if="backup.kind === 'scheduled'"
                            variant="secondary"
                            class="font-normal"
                            data-test="backup-scheduled"
                        >
                            {{ $t('Scheduled') }}
                        </Badge>
                    </span>
                    <span class="text-xs text-muted-foreground">
                        {{ formatDateTime(backup.created_at) }} ·
                        {{ formatBytes(backup.size_bytes) }} ·
                        {{ backup.os_template ?? '—' }} ·
                        <span class="font-mono">{{ backup.storage }}</span>
                    </span>
                    <span
                        v-if="backup.unrestorable_reason && !terminated"
                        class="text-xs text-status-warning-text"
                        data-test="backup-unrestorable"
                    >
                        {{ backup.unrestorable_reason }}
                    </span>
                </span>
                <span class="flex items-center gap-1">
                    <Button
                        v-if="!terminated"
                        variant="outline"
                        size="sm"
                        class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                        :disabled="disabled || busy || !backup.restorable"
                        data-test="restore-backup"
                        @click="startRestore(backup)"
                    >
                        <RotateCcw class="h-4 w-4" />
                        {{ $t('Restore') }}
                    </Button>
                    <ConfirmDelete
                        :action="
                            destroyRoute({
                                server: server,
                                backup: backup.id,
                            })
                        "
                        :name="backup.label ?? $t('No label')"
                        description="The backup is deleted from its storage. The server itself does not change."
                    />
                </span>
            </li>
        </ul>
        <p
            v-else-if="!backups.unavailable"
            class="text-sm text-muted-foreground"
        >
            {{ $t('No backups yet.') }}
        </p>

        <FormDialog
            v-model:open="taking"
            title="Take backup"
            description="The server keeps running. A backup of a large server can take a long time; meanwhile the server takes no other operation."
            submit-label="Take backup"
            :processing="takeForm.processing"
            @submit="take"
        >
            <p v-if="backups.storage" class="text-sm text-muted-foreground">
                {{ $t('Storage: :name', { name: backups.storage }) }}
            </p>
            <FormField
                label="Label"
                for="backup_label"
                :error="takeForm.errors.label"
                hint="Optional, e.g. Before the PHP upgrade."
            >
                <Input
                    id="backup_label"
                    v-model="takeForm.label"
                    maxlength="64"
                />
            </FormField>
        </FormDialog>

        <FormDialog
            v-model:open="scheduling"
            title="Backup schedule"
            description="Scheduled backups are taken in the backup window of Panel settings. Once the backup slots are full, each one deletes the oldest scheduled backup; manual backups are never deleted automatically."
            submit-label="Save"
            :processing="scheduleForm.processing"
            @submit="saveSchedule"
        >
            <FormField
                label="Schedule"
                for="backup_schedule"
                :error="scheduleForm.errors.schedule"
            >
                <NativeSelect
                    id="backup_schedule"
                    v-model="scheduleForm.schedule"
                    :options="scheduleOptions"
                />
            </FormField>
        </FormDialog>

        <FormDialog
            v-model:open="restoreOpen"
            title="Restore backup"
            description="Everything written to the disk since the backup is lost, the snapshots are deleted and apps installed since are removed. The operating system and root password become those of the backup."
            submit-label="Restore"
            :processing="restoreForm.processing"
            destructive
            :submit-disabled="
                !confirmsHostname(restoreForm.confirm_hostname, hostname)
            "
            @submit="restore"
        >
            <p v-if="restoring" class="text-sm">
                {{
                    $t('Backup: :label (:time)', {
                        label: restoring.label ?? $t('No label'),
                        time: formatDateTime(restoring.created_at),
                    })
                }}
            </p>
            <HostnameConfirmField
                id="backup_confirm_hostname"
                v-model="restoreForm.confirm_hostname"
                :hostname="hostname"
                :error="restoreForm.errors.confirm_hostname"
            />
        </FormDialog>
    </section>
</template>
