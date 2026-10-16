<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { RefreshCw } from '@lucide/vue';
import { ref } from 'vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { RouteDefinition } from '@/wayfinder';

/**
 * Choose an OS and SSH keys, type the hostname, and wipe the server.
 */
const props = defineProps<{
    action: RouteDefinition<'post'>;
    hostname: string;
    options: {
        osTemplates: { value: string; label: string }[];
        sshKeys: { value: string; label: string; fingerprint: string }[];
    };
    disabled?: boolean;
}>();

const open = ref(false);

const form = useForm({
    os_template: props.options.osTemplates[0]?.value ?? null,
    // All saved keys are selected by default.
    ssh_keys: props.options.sshKeys.map((key) => key.value),
    confirm_hostname: '',
});

const toggleKey = (value: string, checked: boolean | 'indeterminate') => {
    form.ssh_keys =
        checked === true
            ? [...form.ssh_keys, value]
            : form.ssh_keys.filter((key) => key !== value);
};

const submit = () =>
    form.submit(props.action, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset('confirm_hostname');
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
                            'All data on the server is deleted and the chosen operating system is installed. IP addresses and resources stay the same. A new root password is created; you can see it once on this page.',
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

                <FormField
                    label="SSH keys"
                    :error="form.errors.ssh_keys"
                    hint="Saved keys are managed under Settings → SSH keys."
                >
                    <div class="flex flex-col gap-2">
                        <Label
                            v-for="key in options.sshKeys"
                            :key="key.value"
                            class="flex items-start gap-3 font-normal"
                        >
                            <Checkbox
                                :model-value="form.ssh_keys.includes(key.value)"
                                @update:model-value="
                                    (checked) => toggleKey(key.value, checked)
                                "
                            />
                            <span class="min-w-0">
                                {{ key.label }}
                                <span
                                    class="block font-mono text-xs break-all text-muted-foreground"
                                    >{{ key.fingerprint }}</span
                                >
                            </span>
                        </Label>
                        <p
                            v-if="options.sshKeys.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            {{ $t('No SSH keys saved yet.') }}
                        </p>
                    </div>
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
