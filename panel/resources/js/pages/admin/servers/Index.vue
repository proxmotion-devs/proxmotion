<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Download, Plus } from '@lucide/vue';
import ServerStatusBadge from '@/components/admin/ServerStatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { adopt, create, index, show } from '@/routes/admin/servers';
import type { Paginated, ServerListItem } from '@/types/admin';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Servers', href: index() }] },
});

defineProps<{
    servers: Paginated<ServerListItem>;
    showTerminated: boolean;
}>();

const toggleTerminated = (value: boolean | 'indeterminate') => {
    router.visit(index({ query: value === true ? { terminated: 1 } : {} }), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Servers" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Servers"
                description="Virtual servers managed by the panel."
            />
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" as-child>
                    <Link :href="adopt()">
                        <Download class="h-4 w-4" />
                        {{ $t('Adopt existing VM') }}
                    </Link>
                </Button>
                <Button as-child>
                    <Link :href="create()">
                        <Plus class="h-4 w-4" />
                        {{ $t('Create server') }}
                    </Link>
                </Button>
            </div>
        </div>

        <Label class="flex items-center gap-2 font-normal">
            <Checkbox
                :model-value="showTerminated"
                @update:model-value="toggleTerminated"
            />
            {{ $t('Show deleted servers') }}
        </Label>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Hostname') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Status') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Billing service ID') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('IP addresses') }}
                        </th>
                        <th class="px-4 py-3 font-medium">
                            {{ $t('Package') }}
                        </th>
                        <th class="px-4 py-3 font-medium">{{ $t('Node') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="server in servers.data"
                        :key="server.uuid"
                        class="border-t hover:bg-muted/30"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="show(server.uuid)"
                                class="font-medium hover:underline"
                            >
                                {{ server.hostname }}
                            </Link>
                            <div
                                class="font-mono text-xs text-muted-foreground"
                            >
                                VMID {{ server.vmid }}
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <ServerStatusBadge :status="server.status" />
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">
                            {{ server.external_id ?? '—' }}
                            <div
                                v-if="server.billing_system"
                                class="font-sans text-muted-foreground"
                            >
                                {{ server.billing_system }}
                            </div>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">
                            {{ server.addresses.join(', ') || '—' }}
                        </td>
                        <td class="px-4 py-3">
                            {{ server.package ?? $t('Custom') }}
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-mono text-xs">{{
                                server.node
                            }}</span>
                            <div class="text-xs text-muted-foreground">
                                {{ server.location }}
                            </div>
                        </td>
                    </tr>
                    <tr v-if="servers.data.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-10 text-center text-muted-foreground"
                        >
                            {{ $t('No servers yet.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="servers.last_page > 1" class="flex flex-wrap gap-1">
            <template v-for="link in servers.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="rounded-md border px-3 py-1 text-sm"
                    :class="{
                        'bg-primary text-primary-foreground': link.active,
                    }"
                >
                    <span v-html="link.label" />
                </Link>
            </template>
        </nav>
    </div>
</template>
