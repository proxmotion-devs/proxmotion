<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Archive, LoaderCircle, Plus, RotateCcw } from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useDateTime } from '@/composables/useDateTime';
import { formatBytes } from '@/lib/format';
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
            <h3 class="flex items-center gap-2 font-medium">
                <Archive class="h-4 w-4" />
                {{ $t('Backups') }}
                <Badge
                    variant="secondary"
                    class="font-normal"
                    data-test="backup-count"
                >
                    {{ items.length }} / {{ backups.slots }}
                </Badge>
            </h3>
            <Button
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
        <p class="text-sm text-muted-foreground">
            {{
                $t(
                    'Backups are full copies of the server on a backup storage. They survive reinstalls and are deleted with the server. Restoring one replaces the disk and deletes the snapshots; the panel then puts back the current resources, firewall, addresses, hostname and SSH keys.',
                )
            }}
        </p>
        <p
            v-if="backups.unavailable"
            class="rounded-md border border-dashed px-3 py-2 text-sm text-muted-foreground"
            data-test="backups-unavailable"
        >
            {{ backups.unavailable }}
        </p>
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
                    <span class="font-medium">
                        {{ backup.label ?? $t('No label') }}
                    </span>
                    <span class="text-xs text-muted-foreground">
                        {{ formatDateTime(backup.created_at) }} ·
                        {{ formatBytes(backup.size_bytes) }} ·
                        {{ backup.os_template ?? '—' }} ·
                        <span class="font-mono">{{ backup.storage }}</span>
                    </span>
                    <span
                        v-if="backup.unrestorable_reason"
                        class="text-xs text-amber-700 dark:text-amber-400"
                        data-test="backup-unrestorable"
                    >
                        {{ backup.unrestorable_reason }}
                    </span>
                </span>
                <span class="flex items-center gap-1">
                    <Button
                        variant="outline"
                        size="sm"
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
            v-model:open="restoreOpen"
            title="Restore backup"
            description="Everything written to the disk since the backup is lost, the snapshots are deleted and apps installed since are removed. The operating system and root password become those of the backup."
            submit-label="Restore"
            :processing="restoreForm.processing"
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
            <FormField
                label="Type the hostname to confirm"
                for="backup_confirm_hostname"
                :error="restoreForm.errors.confirm_hostname"
                :hint="hostname"
            >
                <Input
                    id="backup_confirm_hostname"
                    v-model="restoreForm.confirm_hostname"
                    autocomplete="off"
                />
            </FormField>
        </FormDialog>
    </section>
</template>
