<script setup lang="ts">
import { Head, Link, router, setLayoutProps, usePoll } from '@inertiajs/vue3';
import { KeyRound, Pencil, RefreshCw, Send } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ConfirmDelete from '@/components/admin/ConfirmDelete.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import WebhookEndpointDialog from '@/components/admin/WebhookEndpointDialog.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { useDateTime } from '@/composables/useDateTime';
import { index } from '@/routes/admin/billing-systems';
import { redeliver } from '@/routes/admin/webhook-deliveries';
import { destroy, ping, secret, show, update } from '@/routes/admin/webhooks';
import { WEBHOOK_DELIVERY_TONES } from '@/types/admin';
import type {
    Paginated,
    WebhookDelivery,
    WebhookEndpoint,
} from '@/types/admin';

const props = defineProps<{
    endpoint: WebhookEndpoint;
    billingSystem: { uuid: string; name: string };
    deliveries: Paginated<WebhookDelivery>;
    events: { value: string; label: string }[];
    /** Only right after the endpoint was created or its secret replaced. */
    newSecret: string | null;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Billing systems', href: index() },
        { title: props.billingSystem.name, href: index() },
        { title: 'Webhook', href: show(props.endpoint.uuid) },
    ],
});

const { formatDateTime } = useDateTime();

// Refresh while deliveries are still being sent.
const { start, stop } = usePoll(
    5000,
    { only: ['endpoint', 'deliveries'] },
    { autoStart: false },
);
const sending = computed(() =>
    props.deliveries.data.some((delivery) => delivery.status === 'pending'),
);
watch(sending, (value) => (value ? start() : stop()), { immediate: true });

const editOpen = ref(false);
const replaceOpen = ref(false);
const expanded = ref<string | null>(null);

const post = (action: ReturnType<typeof ping>) =>
    router.visit(action, { preserveScroll: true });

const replaceSecret = () => {
    router.visit(secret(props.endpoint.uuid), {
        preserveScroll: true,
        onFinish: () => (replaceOpen.value = false),
    });
};

const prettyPayload = (payload: string) => {
    try {
        return JSON.stringify(JSON.parse(payload), null, 2);
    } catch {
        return payload;
    }
};
</script>

<template>
    <Head title="Webhook" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <Heading
                    title="Webhook"
                    description="Every request carries a Proxmotion-Signature header: t=<timestamp>,v1=<HMAC-SHA256 of timestamp.body with the secret>. Only a 2xx answer counts as delivered; others are retried for about a day."
                />
                <p class="font-mono text-sm break-all">{{ endpoint.url }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <StatusBadge
                        :tone="endpoint.active ? 'success' : 'neutral'"
                        :label="endpoint.active ? 'Active' : 'Disabled'"
                    />
                    <StatusBadge
                        v-if="endpoint.failure_count > 0"
                        tone="danger"
                        :label="
                            $t(':count failed deliveries in a row', {
                                count: String(endpoint.failure_count),
                            })
                        "
                    />
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" @click="post(ping(endpoint.uuid))">
                    <Send class="h-4 w-4" />
                    {{ $t('Send test event') }}
                </Button>
                <Button variant="outline" @click="editOpen = true">
                    <Pencil class="h-4 w-4" />
                    {{ $t('Edit') }}
                </Button>
                <Button variant="outline" @click="replaceOpen = true">
                    <KeyRound class="h-4 w-4" />
                    {{ $t('Replace secret') }}
                </Button>
                <ConfirmDelete
                    :action="destroy(endpoint.uuid)"
                    :name="endpoint.url"
                    description="The billing system is no longer told about events. Deliveries of this endpoint are deleted too."
                    with-label
                />
            </div>
        </div>

        <div
            v-if="newSecret"
            class="flex flex-col gap-2 rounded-xl border border-emerald-500/40 bg-emerald-500/10 p-4"
            data-test="new-secret"
        >
            <p class="flex items-center gap-2 text-sm font-medium">
                <KeyRound class="h-4 w-4" />
                {{
                    $t(
                        'Enter this signing secret in the billing system now: it is shown only once.',
                    )
                }}
            </p>
            <code class="font-mono text-xs break-all select-all">{{
                newSecret
            }}</code>
        </div>

        <section class="flex flex-col gap-3">
            <h2 class="font-semibold">{{ $t('Events') }}</h2>
            <div class="flex flex-wrap gap-2">
                <span
                    v-for="event in endpoint.events"
                    :key="event"
                    class="rounded-md border px-2 py-0.5 font-mono text-xs"
                    >{{ event }}</span
                >
            </div>
        </section>

        <section class="flex flex-col gap-3">
            <h2 class="font-semibold">{{ $t('Deliveries') }}</h2>
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3 font-medium">
                                {{ $t('Created') }}
                            </th>
                            <th class="px-4 py-3 font-medium">
                                {{ $t('Event') }}
                            </th>
                            <th class="px-4 py-3 font-medium">
                                {{ $t('Status') }}
                            </th>
                            <th class="px-4 py-3 font-medium">
                                {{ $t('Attempts') }}
                            </th>
                            <th class="px-4 py-3 font-medium">
                                {{ $t('Result') }}
                            </th>
                            <th class="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody>
                        <template
                            v-for="delivery in deliveries.data"
                            :key="delivery.uuid"
                        >
                            <tr class="border-t align-top">
                                <td class="px-4 py-3 whitespace-nowrap">
                                    {{ formatDateTime(delivery.created_at) }}
                                </td>
                                <td class="px-4 py-3 font-mono text-xs">
                                    {{ delivery.event }}
                                    <div class="text-muted-foreground">
                                        {{ delivery.event_id }}
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <StatusBadge
                                        :tone="
                                            WEBHOOK_DELIVERY_TONES[
                                                delivery.status
                                            ].tone
                                        "
                                        :label="
                                            WEBHOOK_DELIVERY_TONES[
                                                delivery.status
                                            ].label
                                        "
                                    />
                                </td>
                                <td class="px-4 py-3">
                                    {{ delivery.attempts }}
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    <div v-if="delivery.delivered_at">
                                        {{
                                            $t('Delivered :time', {
                                                time: formatDateTime(
                                                    delivery.delivered_at,
                                                ),
                                            })
                                        }}
                                    </div>
                                    <div
                                        v-if="delivery.error"
                                        class="font-mono break-all text-destructive"
                                    >
                                        {{ delivery.error }}
                                    </div>
                                    <div
                                        v-if="delivery.next_attempt_at"
                                        class="text-muted-foreground"
                                    >
                                        {{
                                            $t('Next attempt :time', {
                                                time: formatDateTime(
                                                    delivery.next_attempt_at,
                                                ),
                                            })
                                        }}
                                    </div>
                                </td>
                                <td
                                    class="px-4 py-3 text-right whitespace-nowrap"
                                >
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        @click="
                                            expanded =
                                                expanded === delivery.uuid
                                                    ? null
                                                    : delivery.uuid
                                        "
                                    >
                                        {{ $t('Payload') }}
                                    </Button>
                                    <Button
                                        v-if="delivery.status !== 'pending'"
                                        variant="ghost"
                                        size="sm"
                                        @click="post(redeliver(delivery.uuid))"
                                    >
                                        <RefreshCw class="h-4 w-4" />
                                        {{ $t('Send again') }}
                                    </Button>
                                </td>
                            </tr>
                            <tr v-if="expanded === delivery.uuid">
                                <td colspan="6" class="bg-muted/30 px-4 py-3">
                                    <pre
                                        class="overflow-x-auto font-mono text-xs"
                                        >{{
                                            prettyPayload(delivery.payload)
                                        }}</pre>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="deliveries.data.length === 0">
                            <td
                                colspan="6"
                                class="px-4 py-6 text-center text-muted-foreground"
                            >
                                {{ $t('No deliveries yet.') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav v-if="deliveries.last_page > 1" class="flex flex-wrap gap-1">
                <template v-for="link in deliveries.links" :key="link.label">
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
        </section>
    </div>

    <WebhookEndpointDialog
        v-model:open="editOpen"
        :action="update(endpoint.uuid)"
        :events="events"
        :endpoint="endpoint"
    />

    <Dialog v-model:open="replaceOpen">
        <DialogContent>
            <DialogTitle>{{ $t('Replace the signing secret?') }}</DialogTitle>
            <DialogDescription>
                {{
                    $t(
                        'The old secret stops working at once: requests fail the signature check until the new one is entered in the billing system.',
                    )
                }}
            </DialogDescription>
            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">{{ $t('Cancel') }}</Button>
                </DialogClose>
                <Button variant="destructive" @click="replaceSecret">
                    {{ $t('Replace secret') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
