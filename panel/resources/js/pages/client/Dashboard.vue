<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ServerStatusBadge from '@/components/admin/ServerStatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { dashboard } from '@/routes';
import type { ServerStatus } from '@/types/admin';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

defineProps<{
    servers: {
        uuid: string;
        hostname: string;
        status: ServerStatus;
        location: string;
        package: string;
        os_template: string | null;
        addresses: string[];
    }[];
}>();
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto p-4">
        <Heading
            title="My servers"
            description="Your virtual servers will appear here."
        />

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <article
                v-for="server in servers"
                :key="server.uuid"
                class="flex flex-col gap-3 rounded-xl border p-4"
            >
                <div class="flex items-start justify-between gap-2">
                    <h3 class="font-mono text-sm font-semibold break-all">
                        {{ server.hostname }}
                    </h3>
                    <ServerStatusBadge :status="server.status" />
                </div>
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <div>
                        <dt class="text-muted-foreground">
                            {{ $t('Package') }}
                        </dt>
                        <dd>{{ server.package }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">
                            {{ $t('Location') }}
                        </dt>
                        <dd>{{ server.location }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">
                            {{ $t('Operating system') }}
                        </dt>
                        <dd>{{ server.os_template ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">
                            {{ $t('IP addresses') }}
                        </dt>
                        <dd
                            v-for="address in server.addresses"
                            :key="address"
                            class="font-mono text-xs"
                        >
                            {{ address }}
                        </dd>
                        <dd v-if="server.addresses.length === 0">—</dd>
                    </div>
                </dl>
            </article>
        </div>

        <p
            v-if="servers.length === 0"
            class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            {{ $t('You have no servers yet.') }}
        </p>
    </div>
</template>
