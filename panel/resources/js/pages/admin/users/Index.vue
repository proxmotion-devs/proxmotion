<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref } from 'vue';
import FormDialog from '@/components/admin/FormDialog.vue';
import FormField from '@/components/admin/FormField.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index, store } from '@/routes/admin/users';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Customers', href: index() }] },
});

defineProps<{
    users: {
        uuid: string;
        name: string;
        email: string;
        role: 'admin' | 'client';
        two_factor: boolean;
        servers_count: number;
    }[];
}>();

const dialogOpen = ref(false);
const form = useForm({ name: '', email: '', password: '' });

const openForm = () => {
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
};

const submit = () => {
    form.submit(store(), {
        preserveScroll: true,
        onSuccess: () => (dialogOpen.value = false),
    });
};
</script>

<template>
    <Head title="Customers" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Customers"
                description="Customer accounts. Later, billing systems will create them automatically."
            />
            <Button @click="openForm">
                <Plus class="h-4 w-4" />
                {{ $t('Add customer') }}
            </Button>
        </div>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ $t('Name') }}</th>
                        <th class="px-4 py-3 font-medium">{{ $t('Email') }}</th>
                        <th class="px-4 py-3 font-medium">{{ $t('Role') }}</th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Servers') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="user in users" :key="user.uuid" class="border-t">
                        <td class="px-4 py-3 font-medium">{{ user.name }}</td>
                        <td class="px-4 py-3">{{ user.email }}</td>
                        <td class="px-4 py-3">
                            <StatusBadge
                                :tone="
                                    user.role === 'admin'
                                        ? 'warning'
                                        : 'neutral'
                                "
                                :label="
                                    user.role === 'admin'
                                        ? 'Administrator'
                                        : 'Customer'
                                "
                            />
                            <StatusBadge
                                v-if="user.two_factor"
                                tone="success"
                                label="2FA"
                                class="ml-1"
                            />
                        </td>
                        <td class="px-4 py-3">{{ user.servers_count }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <FormDialog
        v-model:open="dialogOpen"
        title="Add customer"
        :processing="form.processing"
        @submit="submit"
    >
        <FormField label="Name" for="name" :error="form.errors.name">
            <Input id="name" v-model="form.name" required autocomplete="off" />
        </FormField>
        <FormField label="Email address" for="email" :error="form.errors.email">
            <Input
                id="email"
                v-model="form.email"
                type="email"
                required
                autocomplete="off"
            />
        </FormField>
        <FormField
            label="Password"
            for="password"
            :error="form.errors.password"
            hint="The customer can change it in their settings."
        >
            <Input
                id="password"
                v-model="form.password"
                type="password"
                required
                autocomplete="new-password"
            />
        </FormField>
    </FormDialog>
</template>
