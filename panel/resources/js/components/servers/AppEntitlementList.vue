<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { BadgeCheck, Ban } from '@lucide/vue';
import { ref } from 'vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useDateTime } from '@/composables/useDateTime';
import { destroy } from '@/routes/admin/app-entitlements';
import type {
    AppEntitlement,
    AppEntitlementSource,
    Option,
} from '@/types/admin';
import type { RouteDefinition } from '@/wayfinder';

/**
 * Rights to install paid apps on the server (docs/adr/0019): granted by an
 * order, the billing system or an administrator. Revoking only stops new
 * installs; an installed copy stays.
 */
const props = defineProps<{
    entitlements: AppEntitlement[];
    /** Paid apps that fit the server and have no active entitlement. */
    options: Option[];
    action: RouteDefinition<'post'>;
    disabled?: boolean;
}>();

const { formatDateTime } = useDateTime();

const sourceLabels: Record<AppEntitlementSource, string> = {
    order: 'Ordered with the server',
    api: 'Billing system',
    admin: 'Administrator',
};

// Grant
const open = ref(false);
const form = useForm({ app: null as string | null, reference: '' });

const openDialog = () => {
    form.clearErrors();
    form.app = props.options[0]?.value ?? null;
    form.reference = '';
    open.value = true;
};

const grant = () =>
    form.submit(props.action, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });

// Revoke
const revoking = ref<AppEntitlement | null>(null);
const revokeOpen = ref(false);
const revokeForm = useForm({});

const askRevoke = (entitlement: AppEntitlement) => {
    revoking.value = entitlement;
    revokeOpen.value = true;
};

const revoke = () => {
    if (revoking.value) {
        revokeForm.submit(destroy(revoking.value.uuid), {
            preserveScroll: true,
            onSuccess: () => (revokeOpen.value = false),
        });
    }
};
</script>

<template>
    <div class="flex flex-col gap-3">
        <div>
            <Button
                variant="outline"
                :disabled="disabled || options.length === 0"
                @click="openDialog"
            >
                <BadgeCheck class="h-4 w-4" />
                {{ $t('Grant paid app') }}
            </Button>
        </div>

        <div
            v-if="entitlements.length"
            class="overflow-x-auto rounded-xl border"
        >
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ $t('App') }}</th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Granted by') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Reference') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Status') }}
                        </th>
                        <th class="px-4 py-3" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="entitlement in entitlements"
                        :key="entitlement.uuid"
                        class="border-t"
                    >
                        <td class="px-4 py-3 font-medium">
                            {{ entitlement.app }}
                        </td>
                        <td class="px-4 py-3">
                            {{ $t(sourceLabels[entitlement.source]) }}
                            <div class="text-xs text-muted-foreground">
                                {{ formatDateTime(entitlement.created_at) }}
                            </div>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">
                            {{ entitlement.reference ?? '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <StatusBadge
                                v-if="entitlement.active"
                                tone="success"
                                label="Active"
                            />
                            <template v-else>
                                <StatusBadge tone="neutral" label="Revoked" />
                                <div class="text-xs text-muted-foreground">
                                    {{ formatDateTime(entitlement.revoked_at) }}
                                </div>
                            </template>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Button
                                v-if="entitlement.active"
                                variant="ghost"
                                size="sm"
                                @click="askRevoke(entitlement)"
                            >
                                <Ban class="h-4 w-4" />
                                {{ $t('Revoke') }}
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <FormDialog
        v-model:open="open"
        title="Grant paid app"
        description="The app can then be installed on this server, also from the billing system. Usually the billing system grants it when the customer buys the app."
        submit-label="Grant"
        :processing="form.processing"
        @submit="grant"
    >
        <FormField label="App" for="entitlement_app" :error="form.errors.app">
            <NativeSelect
                id="entitlement_app"
                v-model="form.app"
                :options="options"
            />
        </FormField>
        <FormField
            label="Reference"
            for="entitlement_reference"
            :error="form.errors.reference"
            hint="Optional. E.g. the order or addon number in the billing system."
        >
            <Input
                id="entitlement_reference"
                v-model="form.reference"
                maxlength="191"
                autocomplete="off"
            />
        </FormField>
    </FormDialog>

    <FormDialog
        v-model:open="revokeOpen"
        title="Revoke the right to this app?"
        description="The app can no longer be installed on this server. A copy that is already installed stays and keeps working."
        submit-label="Revoke"
        :processing="revokeForm.processing"
        @submit="revoke"
    >
        <p class="text-sm font-medium">{{ revoking?.app }}</p>
    </FormDialog>
</template>
