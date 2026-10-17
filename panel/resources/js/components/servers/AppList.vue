<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { PackagePlus, RotateCcw } from '@lucide/vue';
import { computed, ref } from 'vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useDateTime } from '@/composables/useDateTime';
import { retry } from '@/routes/admin/app-installations';
import type {
    AppInput,
    AppInstallation,
    AppInstallationStatus,
    AppOption,
    AppText,
} from '@/types/admin';
import type { RouteDefinition } from '@/wayfinder';

/**
 * Apps installed on a server (docs/adr/0015) with their progress, and a
 * dialog to install one of the apps that fit the server.
 */
const props = defineProps<{
    apps: AppInstallation[];
    options: AppOption[];
    action: RouteDefinition<'post'>;
    disabled?: boolean;
}>();

const page = usePage<{ locale: string }>();
const { formatDateTime } = useDateTime();

// English keys, translated in the template.
const statusLabels: Record<AppInstallationStatus, string> = {
    queued: 'Queued',
    installing: 'Installing',
    installed: 'Installed',
    failed: 'Failed',
    removed: 'Removed',
};
const statusTone: Record<
    AppInstallationStatus,
    'success' | 'warning' | 'danger' | 'neutral'
> = {
    queued: 'neutral',
    installing: 'warning',
    installed: 'success',
    failed: 'danger',
    removed: 'neutral',
};

const text = (value: AppText | null, fallback: string) =>
    value
        ? ((page.props.locale === 'tr' ? value.tr : null) ?? value.en)
        : fallback;

const busy = computed(() =>
    props.apps.some(
        (app) => app.status === 'queued' || app.status === 'installing',
    ),
);

const open = ref(false);
const form = useForm<{
    app: string | null;
    inputs: Record<string, string | number | boolean>;
}>({ app: null, inputs: {} });

const selected = computed(() =>
    props.options.find((option) => option.slug === form.app),
);

const appOptions = computed(() =>
    props.options.map((option) => ({ value: option.slug, label: option.name })),
);

const resetInputs = () => {
    form.inputs = Object.fromEntries(
        (selected.value?.inputs ?? [])
            .filter((input) => input.default !== null)
            .map((input) => [
                input.key,
                input.default as string | number | boolean,
            ]),
    );
};

const openDialog = () => {
    form.clearErrors();
    form.app = props.options[0]?.slug ?? null;
    resetInputs();
    open.value = true;
};

const inputType = (input: AppInput) =>
    ({
        password: 'password',
        integer: 'number',
        port: 'number',
        email: 'email',
    })[input.type as string] ?? 'text';

const submit = () =>
    form.submit(props.action, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });

const retryInstallation = (app: AppInstallation) =>
    router.visit(retry(app.uuid), { preserveScroll: true });
</script>

<template>
    <div class="flex flex-col gap-3">
        <div>
            <Button
                variant="outline"
                :disabled="disabled || busy || options.length === 0"
                @click="openDialog"
            >
                <PackagePlus class="h-4 w-4" />
                {{ $t('Install app') }}
            </Button>
        </div>

        <div v-if="apps.length" class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ $t('App') }}</th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Status') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Progress') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Started') }}
                        </th>
                        <th class="px-4 py-3" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="app in apps" :key="app.uuid" class="border-t">
                        <td class="px-4 py-3">
                            <span class="font-medium">{{ app.app }}</span>
                            <span class="ml-1 text-xs text-muted-foreground">{{
                                app.version
                            }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <StatusBadge
                                :tone="statusTone[app.status]"
                                :label="statusLabels[app.status]"
                            />
                        </td>
                        <td class="px-4 py-3">
                            <span v-if="app.status === 'installing'"
                                >{{ app.progress }}% · </span
                            >{{ app.step ?? '' }}
                            <p
                                v-if="app.error"
                                class="mt-1 text-xs break-words text-red-600 dark:text-red-400"
                            >
                                {{ app.error }}
                            </p>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{ formatDateTime(app.created_at) }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Button
                                v-if="app.status === 'failed'"
                                variant="outline"
                                size="sm"
                                :disabled="disabled || busy"
                                @click="retryInstallation(app)"
                            >
                                <RotateCcw class="h-4 w-4" />
                                {{ $t('Retry') }}
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else class="text-sm text-muted-foreground">
            {{ $t('No apps installed.') }}
        </p>
    </div>

    <FormDialog
        v-model:open="open"
        title="Install app"
        description="The app is installed inside the server through the guest agent. This can take several minutes; the progress shows here."
        submit-label="Install"
        :processing="form.processing"
        @submit="submit"
    >
        <FormField label="App" for="app" :error="form.errors.app">
            <NativeSelect
                id="app"
                v-model="form.app"
                :options="appOptions"
                @change="resetInputs"
            />
        </FormField>

        <template v-for="input in selected?.inputs ?? []" :key="input.key">
            <Label
                v-if="input.type === 'boolean'"
                class="flex items-center gap-3 font-normal"
            >
                <Checkbox
                    :model-value="form.inputs[input.key] === true"
                    @update:model-value="
                        form.inputs[input.key] = $event === true
                    "
                />
                {{ text(input.label, input.key) }}
            </Label>
            <FormField
                v-else
                :label="text(input.label, input.key)"
                :for="`app_input_${input.key}`"
                :error="
                    (form.errors as Record<string, string>)[
                        `inputs.${input.key}`
                    ]
                "
            >
                <NativeSelect
                    v-if="input.type === 'enum'"
                    :id="`app_input_${input.key}`"
                    v-model="form.inputs[input.key] as string"
                    :options="
                        (input.options ?? []).map((value) => ({
                            value,
                            label: value,
                        }))
                    "
                />
                <Input
                    v-else
                    :id="`app_input_${input.key}`"
                    v-model="form.inputs[input.key] as string"
                    :type="inputType(input)"
                    :min="input.min ?? undefined"
                    :max="input.max ?? undefined"
                    :maxlength="input.max_length ?? undefined"
                    :required="input.required"
                    autocomplete="off"
                />
            </FormField>
        </template>
    </FormDialog>
</template>
