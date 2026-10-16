<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Pencil, Plug, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    destroy,
    index,
    store,
    test,
    update,
} from '@/routes/admin/dns-providers';

type Provider = {
    uuid: string;
    name: string;
    driver: 'none' | 'powerdns';
    api_url: string | null;
    server_id: string | null;
    has_api_key: boolean;
    ip_pools_count: number;
};

defineOptions({
    layout: { breadcrumbs: [{ title: 'DNS providers', href: index() }] },
});

defineProps<{ providers: Provider[] }>();

const driverOptions = computed(() => [
    { value: 'none' as const, label: trans('Record only (export by hand)') },
    { value: 'powerdns' as const, label: 'PowerDNS' },
]);

const dialogOpen = ref(false);
const editing = ref<Provider | null>(null);

const form = useForm({
    name: '',
    driver: 'powerdns' as Provider['driver'] | null,
    api_url: '',
    server_id: 'localhost',
    api_key: '',
});

const openForm = (provider: Provider | null) => {
    editing.value = provider;
    form.clearErrors();
    form.defaults({
        name: provider?.name ?? '',
        driver: provider?.driver ?? 'powerdns',
        api_url: provider?.api_url ?? '',
        server_id: provider?.server_id ?? 'localhost',
        api_key: '',
    });
    form.reset();
    dialogOpen.value = true;
};

const submit = () =>
    form.submit(editing.value ? update(editing.value.uuid) : store(), {
        preserveScroll: true,
        onSuccess: () => (dialogOpen.value = false),
    });

const runTest = (provider: Provider) =>
    router.visit(test(provider.uuid), { preserveScroll: true });
</script>

<template>
    <Head title="DNS providers" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="DNS providers"
                description="Where reverse DNS (PTR records) of IP pools is published. Pools without a provider keep PTRs for export."
            />
            <Button @click="openForm(null)">
                <Plus class="h-4 w-4" />
                {{ $t('Add DNS provider') }}
            </Button>
        </div>

        <ul
            v-if="providers.length > 0"
            class="flex flex-col divide-y rounded-xl border"
        >
            <li
                v-for="provider in providers"
                :key="provider.uuid"
                class="flex flex-wrap items-center justify-between gap-4 p-4"
            >
                <div class="min-w-0 text-sm">
                    <p class="font-medium">{{ provider.name }}</p>
                    <p class="text-muted-foreground">
                        {{
                            provider.driver === 'powerdns'
                                ? `PowerDNS · ${provider.api_url} · ${provider.server_id}`
                                : $t('Record only (export by hand)')
                        }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{
                            $t(':count IP pools', {
                                count: String(provider.ip_pools_count),
                            })
                        }}
                    </p>
                </div>
                <div class="flex gap-1">
                    <Button
                        v-if="provider.driver === 'powerdns'"
                        variant="outline"
                        size="sm"
                        @click="runTest(provider)"
                    >
                        <Plug class="h-4 w-4" />
                        {{ $t('Test connection') }}
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        :aria-label="$t('Edit')"
                        @click="openForm(provider)"
                    >
                        <Pencil class="h-4 w-4" />
                    </Button>
                    <ConfirmDelete
                        :action="destroy(provider.uuid)"
                        :name="provider.name"
                    />
                </div>
            </li>
        </ul>
        <p
            v-else
            class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            {{
                $t(
                    'No DNS providers yet. Reverse DNS is recorded in the panel until you add one.',
                )
            }}
        </p>
    </div>

    <FormDialog
        v-model:open="dialogOpen"
        :title="editing ? 'Edit DNS provider' : 'Add DNS provider'"
        :processing="form.processing"
        @submit="submit"
    >
        <FormField label="Name" for="name" :error="form.errors.name">
            <Input id="name" v-model="form.name" required maxlength="100" />
        </FormField>
        <FormField label="Driver" for="driver" :error="form.errors.driver">
            <NativeSelect
                id="driver"
                v-model="form.driver"
                :options="driverOptions"
            />
        </FormField>
        <template v-if="form.driver === 'powerdns'">
            <FormField
                label="API URL"
                for="api_url"
                :error="form.errors.api_url"
                hint="Base URL of the PowerDNS web server, e.g. https://ns1.example.com:8081"
            >
                <Input
                    id="api_url"
                    v-model="form.api_url"
                    type="url"
                    class="font-mono"
                    placeholder="https://ns1.example.com:8081"
                />
            </FormField>
            <FormField
                label="Server ID"
                for="server_id"
                :error="form.errors.server_id"
            >
                <Input
                    id="server_id"
                    v-model="form.server_id"
                    class="font-mono"
                />
            </FormField>
            <FormField
                label="API key"
                for="api_key"
                :error="form.errors.api_key"
                :hint="
                    editing?.has_api_key
                        ? 'Leave empty to keep the current key.'
                        : undefined
                "
            >
                <Input
                    id="api_key"
                    v-model="form.api_key"
                    type="password"
                    autocomplete="new-password"
                />
            </FormField>
        </template>
    </FormDialog>
</template>
