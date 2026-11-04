<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { RefreshCw } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import FormField from '@/components/admin/FormField.vue';
import HostnameConfirmField from '@/components/admin/HostnameConfirmField.vue';
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
        /** The template the server runs now, when it is still offered. */
        current?: string | null;
        apps: OrderedAppOption[];
    };
    /** The name of the system the server runs now, shown above the choice. */
    currentSystem?: string | null;
    /** The server's current public keys, one per line. */
    sshKeys?: string;
    disabled?: boolean;
}>();

const open = ref(false);

// The system it runs now unless someone picks another: never silently the first one listed.
const initialTemplate = () =>
    props.options.current ?? props.options.osTemplates[0]?.value ?? null;

const templateOptions = computed(() =>
    props.options.osTemplates.map((option) =>
        option.value === props.options.current
            ? {
                  ...option,
                  label: trans(':name (current)', { name: option.label }),
              }
            : option,
    ),
);

const form = useForm({
    os_template: initialTemplate(),
    ssh_keys: props.sshKeys ?? '',
    confirm_hostname: '',
    app: '',
    app_inputs: {} as Record<string, string | number | boolean>,
});

// Each time it opens, the keys the server has now (they may have changed
// since) and its current system again.
watch(open, (value) => {
    if (value) {
        form.ssh_keys = props.sshKeys ?? '';
        form.os_template = initialTemplate();
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
        <DialogContent class="max-h-[90dvh] overflow-y-auto">
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <DialogTitle>{{ $t('Reinstall the server?') }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'All data on the server is deleted and the chosen operating system is installed. IP addresses and resources stay the same. A new root password is created.',
                        )
                    }}
                    {{
                        $t(
                            'Its snapshots are deleted and its apps removed; its backups are kept.',
                        )
                    }}
                </DialogDescription>

                <p
                    v-if="currentSystem"
                    class="text-sm"
                    data-test="reinstall-current-system"
                >
                    <span class="text-muted-foreground"
                        >{{ $t('Runs now') }}:</span
                    >
                    {{ currentSystem }}
                </p>

                <FormField
                    label="Operating system"
                    for="os_template"
                    :error="form.errors.os_template"
                >
                    <NativeSelect
                        id="os_template"
                        v-model="form.os_template"
                        :options="templateOptions"
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
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-xs dark:bg-input/30"
                        placeholder="ssh-ed25519 AAAA… user@host"
                    />
                </FormField>

                <HostnameConfirmField
                    id="confirm_hostname"
                    v-model="form.confirm_hostname"
                    :hostname="hostname"
                    :error="form.errors.confirm_hostname"
                />

                <DialogFooter class="dialog-sticky-footer gap-2">
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
