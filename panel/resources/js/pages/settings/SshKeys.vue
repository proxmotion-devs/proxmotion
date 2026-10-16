<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { KeyRound } from '@lucide/vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import FormField from '@/components/admin/FormField.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useDateTime } from '@/composables/useDateTime';
import { destroy, index, store } from '@/routes/ssh-keys';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'SSH keys',
                href: index(),
            },
        ],
    },
});

defineProps<{
    keys: {
        uuid: string;
        name: string;
        type: string;
        fingerprint: string;
        created_at: string | null;
    }[];
}>();

const { formatDateTime } = useDateTime();

const form = useForm({
    name: '',
    public_key: '',
});

const submit = () =>
    form.submit(store(), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
</script>

<template>
    <Head title="SSH keys" />

    <h1 class="sr-only">{{ $t('SSH keys') }}</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="SSH keys"
            description="Saved public keys are added to the root account of servers you create or reinstall."
        />

        <ul
            v-if="keys.length > 0"
            class="flex flex-col divide-y rounded-xl border"
        >
            <li
                v-for="key in keys"
                :key="key.uuid"
                class="flex items-center justify-between gap-4 p-4"
            >
                <div class="flex min-w-0 items-start gap-3">
                    <KeyRound
                        class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground"
                    />
                    <div class="min-w-0">
                        <p class="font-medium break-all">{{ key.name }}</p>
                        <p
                            class="font-mono text-xs break-all text-muted-foreground"
                        >
                            {{ key.type }} · {{ key.fingerprint }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{
                                $t('Added :time', {
                                    time: formatDateTime(key.created_at),
                                })
                            }}
                        </p>
                    </div>
                </div>
                <ConfirmDelete
                    :action="destroy(key.uuid)"
                    :name="key.name"
                    description="Servers that already have this key keep it until they are reinstalled."
                />
            </li>
        </ul>
        <p v-else class="text-sm text-muted-foreground">
            {{ $t('No SSH keys saved yet.') }}
        </p>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <Heading variant="small" title="Add an SSH key" />
            <FormField label="Name" for="name" :error="form.errors.name">
                <Input
                    id="name"
                    v-model="form.name"
                    required
                    maxlength="100"
                    :placeholder="$t('e.g. Work laptop')"
                />
            </FormField>
            <FormField
                label="Public key"
                for="public_key"
                :error="form.errors.public_key"
                hint="The contents of your .pub file, e.g. ~/.ssh/id_ed25519.pub. Never paste a private key."
            >
                <textarea
                    id="public_key"
                    v-model="form.public_key"
                    required
                    rows="4"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                    placeholder="ssh-ed25519 AAAA… user@host"
                />
            </FormField>
            <div>
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    {{ $t('Add SSH key') }}
                </Button>
            </div>
        </form>
    </div>
</template>
