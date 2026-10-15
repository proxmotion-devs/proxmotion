<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, watch } from 'vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { index as usersIndex } from '@/routes/admin/users';
import { create, index, store } from '@/routes/admin/servers';
import { NETWORK_MODELS } from '@/types/admin';
import type { NetworkModel, Option } from '@/types/admin';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Servers', href: index() },
            { title: 'Create server', href: create() },
        ],
    },
});

const props = defineProps<{
    users: Option[];
    locations: Option[];
    packages: (Option & { network_models: NetworkModel[] })[];
    osTemplates: Option[];
}>();

const form = useForm({
    user: props.users[0]?.value ?? null,
    location: props.locations[0]?.value ?? null,
    package: props.packages[0]?.value ?? null,
    os_template: props.osTemplates[0]?.value ?? null,
    network_model: null as NetworkModel | null,
    hostname: '',
    root_password: '',
    ssh_keys: '',
});

// Only offer the network models the chosen package allows (NAT comes later).
const modelOptions = computed(() => {
    const allowed =
        props.packages.find((pkg) => pkg.value === form.package)
            ?.network_models ?? [];

    return NETWORK_MODELS.filter(
        (model) => allowed.includes(model.value) && model.value !== 'nat',
    ).map((model) => ({
        value: model.value,
        label: trans(model.label),
    }));
});

watch(
    modelOptions,
    (options) => {
        if (!options.some((option) => option.value === form.network_model)) {
            form.network_model = options[0]?.value ?? null;
        }
    },
    { immediate: true },
);

const missing = computed(
    () =>
        props.users.length === 0 ||
        props.locations.length === 0 ||
        props.packages.length === 0 ||
        props.osTemplates.length === 0,
);

// General errors (no capacity, Proxmox unreachable…) come back under the "server" key.
const serverError = computed(
    () => (form.errors as Record<string, string | undefined>).server,
);

const submit = () => form.submit(store());
</script>

<template>
    <Head title="Create server" />

    <div class="flex max-w-2xl flex-col gap-6 p-4">
        <Heading
            title="Create server"
            description="The best node of the location is chosen automatically. Creation continues in the background."
        />

        <p
            v-if="missing"
            class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
        >
            {{
                $t(
                    'You need at least one customer, location, active package and active OS template.',
                )
            }}
            <Link :href="usersIndex()" class="font-medium underline">{{
                $t('Customers')
            }}</Link>
        </p>

        <form class="grid gap-4" @submit.prevent="submit">
            <InputError :message="serverError" />

            <FormField label="Customer" for="user" :error="form.errors.user">
                <NativeSelect
                    id="user"
                    v-model="form.user"
                    :options="users"
                    placeholder="Choose a customer"
                />
            </FormField>

            <div class="grid gap-4 sm:grid-cols-2">
                <FormField
                    label="Location"
                    for="location"
                    :error="form.errors.location"
                >
                    <NativeSelect
                        id="location"
                        v-model="form.location"
                        :options="locations"
                    />
                </FormField>
                <FormField
                    label="Package"
                    for="package"
                    :error="form.errors.package"
                >
                    <NativeSelect
                        id="package"
                        v-model="form.package"
                        :options="packages"
                    />
                </FormField>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <FormField
                    label="Operating system"
                    for="os_template"
                    :error="form.errors.os_template"
                >
                    <NativeSelect
                        id="os_template"
                        v-model="form.os_template"
                        :options="osTemplates"
                    />
                </FormField>
                <FormField
                    label="Network model"
                    for="network_model"
                    :error="form.errors.network_model"
                >
                    <NativeSelect
                        id="network_model"
                        v-model="form.network_model"
                        :options="modelOptions"
                    />
                </FormField>
            </div>

            <FormField
                label="Hostname"
                for="hostname"
                :error="form.errors.hostname"
            >
                <Input
                    id="hostname"
                    v-model="form.hostname"
                    required
                    class="font-mono"
                    placeholder="web1.example.com"
                />
            </FormField>

            <FormField
                label="Root password"
                for="root_password"
                :error="form.errors.root_password"
                hint="Leave empty to generate a random password. At least 12 characters."
            >
                <Input
                    id="root_password"
                    v-model="form.root_password"
                    type="password"
                    autocomplete="new-password"
                />
            </FormField>

            <FormField
                label="SSH public keys"
                for="ssh_keys"
                :error="form.errors.ssh_keys"
                hint="Optional. One key per line."
            >
                <textarea
                    id="ssh_keys"
                    v-model="form.ssh_keys"
                    rows="3"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                    placeholder="ssh-ed25519 AAAA… user@host"
                />
            </FormField>

            <div>
                <Button type="submit" :disabled="form.processing || missing">
                    <Spinner v-if="form.processing" />
                    {{ $t('Create server') }}
                </Button>
            </div>
        </form>
    </div>
</template>
