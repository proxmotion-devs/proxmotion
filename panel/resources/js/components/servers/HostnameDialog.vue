<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Tag } from '@lucide/vue';
import { ref } from 'vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { OsPlatform } from '@/types/admin';
import type { RouteDefinition } from '@/wayfinder';

/**
 * Rename the server (docs/adr/0012): in the panel at once, inside the server
 * through the guest agent. Windows applies the name at its next restart.
 */
const props = defineProps<{
    action: RouteDefinition<'post'>;
    hostname: string;
    platform: OsPlatform;
    disabled?: boolean;
}>();

const open = ref(false);
const form = useForm({ hostname: props.hostname, reboot: false });

const openDialog = () => {
    form.clearErrors();
    form.defaults({ hostname: props.hostname, reboot: false });
    form.reset();
    open.value = true;
};

const submit = () =>
    form.submit(props.action, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
</script>

<template>
    <Button variant="outline" :disabled="disabled" @click="openDialog">
        <Tag class="h-4 w-4" />
        {{ $t('Change hostname') }}
    </Button>

    <FormDialog
        v-model:open="open"
        title="Change hostname"
        description="The name changes in the panel at once and inside the server through the guest agent. Reverse DNS records that equal the old name follow it. The Proxmox VM keeps its name until a reinstall."
        submit-label="Change"
        :processing="form.processing"
        @submit="submit"
    >
        <FormField
            label="Hostname"
            for="new_hostname"
            :error="form.errors.hostname"
        >
            <Input
                id="new_hostname"
                v-model="form.hostname"
                class="font-mono"
                autocomplete="off"
                required
                placeholder="web1.example.com"
            />
        </FormField>

        <template v-if="platform === 'windows'">
            <p class="text-sm text-muted-foreground">
                {{
                    $t(
                        'Windows uses the new name after a restart, and only its first part (at most 15 characters).',
                    )
                }}
            </p>
            <Label class="flex items-center gap-3 font-normal">
                <Checkbox v-model="form.reboot" />
                {{ $t('Restart the server now') }}
            </Label>
        </template>
    </FormDialog>
</template>
