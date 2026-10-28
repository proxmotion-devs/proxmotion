<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { KeyRound, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import { Button } from '@/components/ui/button';
import { sshKeys as sshKeysRoute } from '@/routes/admin/servers';
import type { ServerSshKeys, SshKey } from '@/types/admin';

/**
 * The SSH keys the panel manages on a running server (docs/adr/0049). Adding
 * and deleting send the whole new list, like the billing API; a
 * change_ssh_keys task writes it into the server and the list changes once
 * that succeeded.
 */
const props = defineProps<{
    server: string;
    sshKeys: ServerSshKeys;
    disabled?: boolean;
}>();

const lines = computed(() => props.sshKeys.keys.map((key) => key.public_key));
const full = computed(() => props.sshKeys.keys.length >= props.sshKeys.max);

const form = useForm({ ssh_keys: [] as string[] });

const adding = ref(false);
const newKeys = ref('');

const openAdd = () => {
    form.clearErrors();
    newKeys.value = '';
    adding.value = true;
};

const add = () => {
    form.ssh_keys = [...lines.value, newKeys.value];
    form.submit(sshKeysRoute(props.server), {
        preserveScroll: true,
        onSuccess: () => (adding.value = false),
    });
};

const deleting = ref<SshKey | null>(null);
const deleteOpen = computed({
    get: () => deleting.value !== null,
    set: (open: boolean) => {
        if (!open) {
            deleting.value = null;
        }
    },
});

const remove = () => {
    const key = deleting.value;

    if (key === null) {
        return;
    }

    form.ssh_keys = props.sshKeys.keys
        .filter((other) => other.fingerprint !== key.fingerprint)
        .map((other) => other.public_key);
    form.submit(sshKeysRoute(props.server), {
        preserveScroll: true,
        onFinish: () => (deleting.value = null),
    });
};
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-xl border p-4"
        data-test="server-ssh-keys"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="flex items-center gap-2 font-medium">
                <KeyRound class="h-4 w-4" />
                {{ $t('SSH keys') }}
            </h3>
            <Button
                variant="outline"
                size="sm"
                :disabled="disabled || full"
                data-test="add-ssh-key"
                @click="openAdd"
            >
                <Plus class="h-4 w-4" />
                {{ $t('Add SSH key') }}
            </Button>
        </div>
        <p class="text-sm text-muted-foreground">
            {{
                $t(
                    'Keys that can log in as root (Administrator on Windows), written into the server through the guest agent. Keys added inside the server are not listed and stay.',
                )
            }}
        </p>
        <p v-if="form.errors.ssh_keys" class="text-sm text-destructive">
            {{ form.errors.ssh_keys }}
        </p>

        <ul v-if="sshKeys.keys.length" class="divide-y rounded-md border">
            <li
                v-for="key in sshKeys.keys"
                :key="key.fingerprint"
                class="flex items-center justify-between gap-2 px-3 py-2 text-sm"
            >
                <div class="min-w-0">
                    <div class="truncate font-medium">
                        {{ key.comment || $t('No comment') }}
                    </div>
                    <div
                        class="truncate font-mono text-xs text-muted-foreground"
                    >
                        {{ key.type }} · {{ key.fingerprint }}
                    </div>
                </div>
                <Button
                    variant="ghost"
                    size="icon"
                    class="shrink-0 text-destructive hover:bg-destructive/10 hover:text-destructive"
                    :disabled="disabled"
                    :aria-label="
                        $t('Delete :name', { name: key.comment || key.type })
                    "
                    :title="
                        $t('Delete :name', { name: key.comment || key.type })
                    "
                    @click="deleting = key"
                >
                    <Trash2 class="h-4 w-4" />
                </Button>
            </li>
        </ul>
        <p v-else class="text-sm text-muted-foreground">
            {{ $t('The panel manages no SSH keys on this server.') }}
        </p>

        <FormDialog
            v-model:open="adding"
            title="Add SSH key"
            description="One public key per line. The server can have at most 20 keys."
            submit-label="Add"
            :processing="form.processing"
            @submit="add"
        >
            <FormField
                label="SSH public keys"
                for="new_ssh_keys"
                :error="
                    (form.errors as Record<string, string>)[
                        `ssh_keys.${lines.length}`
                    ] ?? form.errors.ssh_keys
                "
            >
                <textarea
                    id="new_ssh_keys"
                    v-model="newKeys"
                    rows="4"
                    required
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                    placeholder="ssh-ed25519 AAAA… user@host"
                />
            </FormField>
        </FormDialog>

        <FormDialog
            v-model:open="deleteOpen"
            title="Delete SSH key"
            description="The key is removed from the server's authorized keys and can no longer log in."
            submit-label="Delete"
            :processing="form.processing"
            @submit="remove"
        >
            <p
                v-if="deleting"
                class="font-mono text-xs break-all text-muted-foreground"
            >
                {{ deleting.type }} · {{ deleting.fingerprint }}
                <template v-if="deleting.comment">
                    · {{ deleting.comment }}</template
                >
            </p>
        </FormDialog>
    </section>
</template>
