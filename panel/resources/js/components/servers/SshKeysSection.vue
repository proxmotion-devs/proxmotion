<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    Check,
    Copy,
    KeyRound,
    LoaderCircle,
    Plus,
    Search,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { sshKeys as sshKeysRoute } from '@/routes/admin/servers';
import type { ServerSshKeys, SshKey } from '@/types/admin';

/**
 * The SSH keys the panel manages on a running server (docs/adr/0049), on
 * their own tab of the server page. Adding and deleting send the whole new
 * list, like the billing API; a change_ssh_keys task writes it into the
 * server and the list changes once that succeeded.
 */
const props = defineProps<{
    server: string;
    sshKeys: ServerSshKeys;
    /** A change_ssh_keys task is queued or running. */
    changing?: boolean;
    disabled?: boolean;
}>();

// Key types as people know them; security keys (FIDO) get a note.
const TYPES: Record<string, { name: string; securityKey: boolean }> = {
    'ssh-ed25519': { name: 'Ed25519', securityKey: false },
    'ssh-rsa': { name: 'RSA', securityKey: false },
    'ecdsa-sha2-nistp256': { name: 'ECDSA P-256', securityKey: false },
    'ecdsa-sha2-nistp384': { name: 'ECDSA P-384', securityKey: false },
    'ecdsa-sha2-nistp521': { name: 'ECDSA P-521', securityKey: false },
    'sk-ssh-ed25519@openssh.com': { name: 'Ed25519', securityKey: true },
    'sk-ecdsa-sha2-nistp256@openssh.com': {
        name: 'ECDSA P-256',
        securityKey: true,
    },
};
const typeOf = (key: SshKey) =>
    TYPES[key.type] ?? { name: key.type, securityKey: false };

const keys = computed(() => props.sshKeys.keys);
const full = computed(() => keys.value.length >= props.sshKeys.max);

// The filter appears once the list is long enough to need one.
const FILTER_FROM = 6;
const filter = ref('');
const shown = computed(() => {
    const needle = filter.value.trim().toLowerCase();

    return keys.value
        .map((key, index) => ({ key, position: index + 1 }))
        .filter(
            ({ key }) =>
                needle === '' ||
                [key.comment, key.type, key.fingerprint].some((value) =>
                    value.toLowerCase().includes(needle),
                ),
        );
});

const form = useForm({ ssh_keys: [] as string[] });

const adding = ref(false);
const newKeys = ref('');

const openAdd = () => {
    form.clearErrors();
    newKeys.value = '';
    adding.value = true;
};

const add = () => {
    form.ssh_keys = [...keys.value.map((key) => key.public_key), newKeys.value];
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

const startDelete = (key: SshKey) => {
    form.clearErrors();
    deleting.value = key;
};

const remove = () => {
    const key = deleting.value;

    if (key === null) {
        return;
    }

    form.ssh_keys = keys.value
        .filter((other) => other.fingerprint !== key.fingerprint)
        .map((other) => other.public_key);
    form.submit(sshKeysRoute(props.server), {
        preserveScroll: true,
        // On an error the dialog stays open and shows it.
        onSuccess: () => (deleting.value = null),
    });
};

// The panel's refusal: about the list, or about one of its keys (ssh_keys.N).
const error = computed(() => {
    const errors = form.errors as Record<string, string>;

    return (
        errors.ssh_keys ??
        Object.entries(errors).find(([field]) =>
            field.startsWith('ssh_keys.'),
        )?.[1] ??
        null
    );
});

// Browsers offer the clipboard only on HTTPS (and localhost).
const canCopy =
    typeof navigator !== 'undefined' && navigator.clipboard !== undefined;

// The fingerprint of the key just copied, for a moment.
const copied = ref<string | null>(null);
let copiedTimer: ReturnType<typeof setTimeout> | undefined;

const copy = async (key: SshKey) => {
    try {
        await navigator.clipboard.writeText(key.public_key);
    } catch {
        return;
    }

    copied.value = key.fingerprint;
    clearTimeout(copiedTimer);
    copiedTimer = setTimeout(() => (copied.value = null), 2000);
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
                <Badge
                    variant="secondary"
                    class="font-normal"
                    data-test="ssh-key-count"
                >
                    {{ keys.length }} / {{ sshKeys.max }}
                </Badge>
            </h3>
            <Button
                size="sm"
                :disabled="disabled || full || changing"
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

        <p
            v-if="changing"
            class="flex items-center gap-2 rounded-md border border-dashed px-3 py-2 text-sm text-muted-foreground"
            data-test="ssh-keys-changing"
        >
            <LoaderCircle class="h-4 w-4 animate-spin" />
            {{
                $t(
                    'The SSH keys are being changed inside the server; the list follows once that worked.',
                )
            }}
        </p>
        <p
            v-if="error && !adding && !deleting"
            class="text-sm text-destructive"
            data-test="ssh-keys-error"
        >
            {{ error }}
        </p>

        <div v-if="keys.length >= FILTER_FROM" class="relative w-full max-w-xs">
            <Search
                class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                v-model="filter"
                type="search"
                class="h-8 pl-8"
                :placeholder="$t('Filter by name, type or fingerprint')"
                :aria-label="$t('Filter by name, type or fingerprint')"
            />
        </div>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[40rem] text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="w-10 px-3 py-2 font-medium">#</th>
                        <th class="px-2 py-2 font-medium">{{ $t('Name') }}</th>
                        <th class="w-40 px-2 py-2 font-medium">
                            {{ $t('Type') }}
                        </th>
                        <th class="px-2 py-2 font-medium">
                            {{ $t('Fingerprint') }}
                        </th>
                        <th class="w-24 px-2 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="{ key, position } in shown"
                        :key="key.fingerprint"
                        class="border-t"
                        data-test="ssh-key"
                    >
                        <td
                            class="px-3 py-2 font-mono text-xs text-muted-foreground"
                        >
                            {{ position }}
                        </td>
                        <td class="max-w-56 px-2 py-2">
                            <span
                                v-if="key.comment"
                                class="block truncate font-medium"
                                :title="key.comment"
                                >{{ key.comment }}</span
                            >
                            <span v-else class="text-muted-foreground italic">{{
                                $t('No comment')
                            }}</span>
                        </td>
                        <td class="px-2 py-2">
                            <Badge variant="outline" :title="key.type">{{
                                typeOf(key).name
                            }}</Badge>
                            <span
                                v-if="typeOf(key).securityKey"
                                class="ml-1 text-xs text-muted-foreground"
                                >{{ $t('Security key') }}</span
                            >
                        </td>
                        <td class="max-w-72 px-2 py-2">
                            <code
                                class="block truncate font-mono text-xs text-muted-foreground"
                                :title="key.fingerprint"
                                >{{ key.fingerprint }}</code
                            >
                        </td>
                        <td class="px-2 py-2">
                            <div class="flex justify-end gap-1">
                                <Button
                                    v-if="canCopy"
                                    variant="ghost"
                                    size="icon"
                                    class="h-8 w-8"
                                    :aria-label="$t('Copy public key')"
                                    :title="
                                        copied === key.fingerprint
                                            ? $t('Copied')
                                            : $t('Copy public key')
                                    "
                                    @click="copy(key)"
                                >
                                    <Check
                                        v-if="copied === key.fingerprint"
                                        class="h-4 w-4 text-green-600"
                                    />
                                    <Copy v-else class="h-4 w-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="h-8 w-8 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                    :disabled="disabled || changing"
                                    :aria-label="
                                        $t('Delete :name', {
                                            name: key.comment || key.type,
                                        })
                                    "
                                    :title="
                                        $t('Delete :name', {
                                            name: key.comment || key.type,
                                        })
                                    "
                                    data-test="delete-ssh-key"
                                    @click="startDelete(key)"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="keys.length === 0" class="border-t">
                        <td
                            colspan="5"
                            class="px-3 py-6 text-center text-muted-foreground"
                        >
                            {{
                                $t(
                                    'The panel manages no SSH keys on this server.',
                                )
                            }}
                        </td>
                    </tr>
                    <tr v-else-if="shown.length === 0" class="border-t">
                        <td
                            colspan="5"
                            class="px-3 py-6 text-center text-muted-foreground"
                        >
                            {{ $t('No key matches the filter.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-if="full" class="text-xs text-muted-foreground">
            {{
                $t(
                    'The server has the most keys it can have; delete one to add another.',
                )
            }}
        </p>

        <FormDialog
            v-model:open="adding"
            title="Add SSH key"
            description="One public key per line."
            submit-label="Add"
            :processing="form.processing"
            @submit="add"
        >
            <FormField
                label="SSH public keys"
                for="new_ssh_keys"
                :error="error ?? undefined"
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
            <p class="text-xs text-muted-foreground">
                {{
                    $t('The server can have at most :count keys.', {
                        count: String(sshKeys.max),
                    })
                }}
            </p>
        </FormDialog>

        <FormDialog
            v-model:open="deleteOpen"
            title="Delete SSH key"
            description="The key is removed from the server's authorized keys and can no longer log in."
            submit-label="Delete"
            :processing="form.processing"
            @submit="remove"
        >
            <dl v-if="deleting" class="grid gap-1 text-sm">
                <dt class="text-muted-foreground">{{ $t('Name') }}</dt>
                <dd>{{ deleting.comment || $t('No comment') }}</dd>
                <dt class="text-muted-foreground">{{ $t('Fingerprint') }}</dt>
                <dd class="font-mono text-xs break-all">
                    {{ deleting.fingerprint }}
                </dd>
            </dl>
            <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
        </FormDialog>
    </section>
</template>
