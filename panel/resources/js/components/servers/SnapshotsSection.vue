<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Camera, LoaderCircle, Plus, RotateCcw } from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useDateTime } from '@/composables/useDateTime';
import {
    destroy as destroyRoute,
    rollback as rollbackRoute,
    store as storeRoute,
} from '@/routes/admin/servers/snapshots';
import type { ServerSnapshot, ServerSnapshots } from '@/types/admin';

/**
 * Snapshots of a server's disk and configuration, without RAM
 * (docs/adr/0051 §3), on their own tab of the server page. Taking, rolling
 * back and deleting are tasks; the list follows once they succeeded. A
 * rollback is confirmed by typing the hostname, like a reinstall.
 */
const props = defineProps<{
    server: string;
    hostname: string;
    snapshots: ServerSnapshots;
    /** A snapshot task is queued or running. */
    busy?: boolean;
    disabled?: boolean;
}>();

const { formatDateTime } = useDateTime();

const items = computed(() => props.snapshots.items);
const full = computed(() => items.value.length >= props.snapshots.slots);

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

const rollbackForm = useForm({ confirm_hostname: '' });
const rollingBack = ref<ServerSnapshot | null>(null);
const rollbackOpen = computed({
    get: () => rollingBack.value !== null,
    set: (open: boolean) => {
        if (!open) {
            rollingBack.value = null;
        }
    },
});

const startRollback = (snapshot: ServerSnapshot) => {
    rollbackForm.reset();
    rollbackForm.clearErrors();
    rollingBack.value = snapshot;
};

const rollback = () => {
    const snapshot = rollingBack.value;

    if (snapshot !== null) {
        rollbackForm.submit(
            rollbackRoute({ server: props.server, snapshot: snapshot.id }),
            {
                preserveScroll: true,
                onSuccess: () => (rollingBack.value = null),
            },
        );
    }
};
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-xl border p-4"
        data-test="server-snapshots"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="flex items-center gap-2 font-medium">
                <Camera class="h-4 w-4" />
                {{ $t('Snapshots') }}
                <Badge
                    variant="secondary"
                    class="font-normal"
                    data-test="snapshot-count"
                >
                    {{ items.length }} / {{ snapshots.slots }}
                </Badge>
            </h3>
            <Button
                size="sm"
                :disabled="
                    disabled || busy || full || snapshots.unavailable !== null
                "
                data-test="take-snapshot"
                @click="openTake"
            >
                <Plus class="h-4 w-4" />
                {{ $t('Take snapshot') }}
            </Button>
        </div>
        <p class="text-sm text-muted-foreground">
            {{
                $t(
                    "Snapshots keep the server's disk and settings, not its memory, on the server's own storage. A reinstall deletes them, and the disk cannot grow while there are any. Rolling back takes the disk to that moment; the panel then puts back the current resources, firewall, addresses, hostname and SSH keys.",
                )
            }}
        </p>
        <p
            v-if="snapshots.unavailable"
            class="rounded-md border border-dashed px-3 py-2 text-sm text-muted-foreground"
            data-test="snapshots-unavailable"
        >
            {{ snapshots.unavailable }}
        </p>
        <p
            v-if="busy"
            class="flex items-center gap-2 rounded-md border border-dashed px-3 py-2 text-sm text-muted-foreground"
            data-test="snapshots-busy"
        >
            <LoaderCircle class="h-4 w-4 animate-spin" />
            {{
                $t(
                    'A snapshot task is running; the list follows once it is done.',
                )
            }}
        </p>

        <ul v-if="items.length > 0" class="grid gap-2 text-sm">
            <li
                v-for="snapshot in items"
                :key="snapshot.id"
                class="flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2"
                data-test="snapshot"
            >
                <span class="grid">
                    <span class="font-medium">
                        {{ snapshot.label ?? $t('No label') }}
                    </span>
                    <span class="text-xs text-muted-foreground">
                        {{ formatDateTime(snapshot.created_at) }}
                    </span>
                </span>
                <span class="flex items-center gap-1">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="disabled || busy"
                        data-test="rollback-snapshot"
                        @click="startRollback(snapshot)"
                    >
                        <RotateCcw class="h-4 w-4" />
                        {{ $t('Roll back') }}
                    </Button>
                    <ConfirmDelete
                        :action="
                            destroyRoute({
                                server: server,
                                snapshot: snapshot.id,
                            })
                        "
                        :name="snapshot.label ?? $t('No label')"
                        description="The snapshot is deleted from Proxmox. The server itself does not change."
                    />
                </span>
            </li>
        </ul>
        <p
            v-else-if="!snapshots.unavailable"
            class="text-sm text-muted-foreground"
        >
            {{ $t('No snapshots yet.') }}
        </p>

        <FormDialog
            v-model:open="taking"
            title="Take snapshot"
            description="The server keeps running. Taking it may slow the server down for a moment."
            submit-label="Take snapshot"
            :processing="takeForm.processing"
            @submit="take"
        >
            <FormField
                label="Label"
                for="snapshot_label"
                :error="takeForm.errors.label"
                hint="Optional, e.g. Before the PHP upgrade."
            >
                <Input
                    id="snapshot_label"
                    v-model="takeForm.label"
                    maxlength="64"
                />
            </FormField>
        </FormDialog>

        <FormDialog
            v-model:open="rollbackOpen"
            title="Roll back to snapshot"
            description="Everything written to the disk since the snapshot is lost, and apps installed since are removed. The root password becomes the one of that time."
            submit-label="Roll back"
            :processing="rollbackForm.processing"
            @submit="rollback"
        >
            <p v-if="rollingBack" class="text-sm">
                {{
                    $t('Snapshot: :label (:time)', {
                        label: rollingBack.label ?? $t('No label'),
                        time: formatDateTime(rollingBack.created_at),
                    })
                }}
            </p>
            <FormField
                label="Type the hostname to confirm"
                for="snapshot_confirm_hostname"
                :error="rollbackForm.errors.confirm_hostname"
                :hint="hostname"
            >
                <Input
                    id="snapshot_confirm_hostname"
                    v-model="rollbackForm.confirm_hostname"
                    autocomplete="off"
                />
            </FormField>
        </FormDialog>
    </section>
</template>
