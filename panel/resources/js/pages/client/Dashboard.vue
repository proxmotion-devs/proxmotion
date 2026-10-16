<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Monitor } from '@lucide/vue';
import ServerStatusBadge from '@/components/admin/ServerStatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { console as openConsole, show } from '@/routes/servers';
import type { Paginated, ServerStatus } from '@/types/admin';

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
    servers: Paginated<{
        uuid: string;
        hostname: string;
        status: ServerStatus;
        location: string;
        package: string | null;
        os_template: string | null;
        addresses: string[];
    }>;
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
                v-for="server in servers.data"
                :key="server.uuid"
                class="flex flex-col gap-3 rounded-xl border p-4"
            >
                <div class="flex items-start justify-between gap-2">
                    <Link
                        :href="show(server.uuid)"
                        class="font-mono text-sm font-semibold break-all hover:underline"
                    >
                        {{ server.hostname }}
                    </Link>
                    <ServerStatusBadge :status="server.status" />
                </div>
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <div>
                        <dt class="text-muted-foreground">
                            {{ $t('Package') }}
                        </dt>
                        <dd>{{ server.package ?? $t('Custom') }}</dd>
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
                <div class="flex flex-wrap gap-2">
                    <Button as-child variant="outline" size="sm">
                        <Link :href="show(server.uuid)">
                            {{ $t('Manage') }}
                        </Link>
                    </Button>
                    <Button
                        v-if="server.status === 'active'"
                        as-child
                        variant="outline"
                        size="sm"
                    >
                        <a
                            :href="openConsole(server.uuid).url"
                            target="_blank"
                            rel="noopener"
                        >
                            <Monitor class="h-4 w-4" />
                            {{ $t('Console') }}
                        </a>
                    </Button>
                </div>
            </article>
        </div>

        <p
            v-if="servers.total === 0"
            class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            {{ $t('You have no servers yet.') }}
        </p>

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
