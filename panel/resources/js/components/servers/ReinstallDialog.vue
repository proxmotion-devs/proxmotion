<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { RefreshCw } from '@lucide/vue';
import { ref, watch } from 'vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import OrderedAppFields from '@/components/servers/OrderedAppFields.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import type { OrderedAppOption } from '@/types/admin';
import type { RouteDefinition } from '@/wayfinder';

/**
 * Choose an OS, keep or change the SSH keys and pick an app installed on the
 * new system, type the hostname, and wipe the server. The keys start as the
 * server's current ones (docs/adr/0049): the new system gets exactly the
 * keys in the field.
 */
const props = defineProps<{
    action: RouteDefinition<'post'>;
    hostname: string;
    options: {
        osTemplates: { value: string; label: string }[];
        apps: OrderedAppOption[];
    };
    /** The server's current public keys, one per line. */
    sshKeys?: string;
    disabled?: boolean;
}>();

const open = ref(false);

const form = useForm({
    os_template: props.options.osTemplates[0]?.value ?? null,
    ssh_keys: props.sshKeys ?? '',
    confirm_hostname: '',
    app: '',
    app_inputs: {} as Record<string, string | number | boolean>,
});

// Each time it opens, the keys the server has now (they may have changed since).
watch(open, (value) => {
    if (value) {
        form.ssh_keys = props.sshKeys ?? '';
    }
});

const submit = () =>
    form.submit(props.action, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset('confirm_hostname', 'app', 'app_inputs');
        },
    });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button
                variant="outline"
                size="sm"
                class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                :disabled="disabled || options.osTemplates.length === 0"
            >
                <RefreshCw class="h-4 w-4" />
                {{ $t('Reinstall') }}
            </Button>
        </DialogTrigger>
        <DialogContent>
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <DialogTitle>{{ $t('Reinstall the server?') }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'All data on the server is deleted and the chosen operating system is installed. IP addresses and resources stay the same. A new root password is created.',
                        )
                    }}
                </DialogDescription>

                <FormField
                    label="Operating system"
                    for="os_template"
                    :error="form.errors.os_template"
                >
                    <NativeSelect
                        id="os_template"
                        v-model="form.os_template"
                        :options="options.osTemplates"
                    />
                </FormField>

                <OrderedAppFields
                    v-model:app="form.app"
                    v-model:inputs="form.app_inputs"
                    :options="options.apps"
                    :os-template="form.os_template"
                    :errors="form.errors as Record<string, string>"
                />

                <FormField
                    label="SSH public keys"
                    for="ssh_keys"
                    :error="form.errors.ssh_keys"
                    hint="The server's current keys; the new system gets exactly these. One key per line."
                >
                    <textarea
                        id="ssh_keys"
                        v-model="form.ssh_keys"
                        rows="3"
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                        placeholder="ssh-ed25519 AAAA… user@host"
                    />
                </FormField>

                <FormField
                    label="Type the hostname to confirm"
                    for="confirm_hostname"
                    :error="form.errors.confirm_hostname"
                >
                    <Input
                        id="confirm_hostname"
                        v-model="form.confirm_hostname"
                        autocomplete="off"
                        :placeholder="hostname"
                        class="font-mono"
                    />
                </FormField>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="secondary">{{
                            $t('Cancel')
                        }}</Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="
                            form.processing ||
                            form.os_template === null ||
                            form.confirm_hostname.trim().toLowerCase() !==
                                hostname.toLowerCase()
                        "
                    >
                        <Spinner v-if="form.processing" />
                        {{ $t('Reinstall') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
