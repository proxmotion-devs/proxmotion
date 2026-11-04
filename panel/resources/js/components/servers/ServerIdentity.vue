<script setup lang="ts">
import { computed } from 'vue';
import CopyButton from '@/components/admin/CopyButton.vue';
import type { ServerAddress } from '@/types/admin';

/**
 * Who the server is, in the order a ticket names it: its primary addresses
 * (to copy), its billing service and customer, its location, then muted the
 * Proxmox side (VMID, node, OS template) sysadmins look for. Under the
 * server page's title; wraps on phones.
 */
const props = defineProps<{
    server: {
        hostname: string;
        vm_name: string | null;
        addresses: ServerAddress[];
        billing_system: string | null;
        external_id: string | null;
        external_client_id: string | null;
        location: string;
        vmid: number;
        node: string;
        os_template: string | null;
    };
}>();

const primaries = computed(() =>
    props.server.addresses.filter((address) => address.primary),
);
</script>

<template>
    <dl
        class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm"
        data-test="server-identity"
    >
        <!-- With a generated hostname the title is its address; the hostname still names it. -->
        <div v-if="server.vm_name">
            <dt class="sr-only">{{ $t('Hostname') }}</dt>
            <dd class="break-all">{{ server.hostname }}</dd>
        </div>
        <div
            v-for="address in primaries"
            :key="address.address"
            data-test="server-identity-address"
        >
            <dt class="sr-only">
                {{
                    address.version === 4
                        ? $t('Primary IPv4 address')
                        : $t('Primary IPv6 address')
                }}
            </dt>
            <dd class="flex items-center">
                <span class="font-mono break-all">{{ address.address }}</span>
                <CopyButton :value="address.address" label="Copy IP address" />
            </dd>
        </div>
        <div data-test="server-identity-billing">
            <dt class="sr-only">{{ $t('Billing') }}</dt>
            <dd
                v-if="server.billing_system || server.external_id"
                class="flex flex-wrap gap-x-2"
            >
                <span v-if="server.billing_system">{{
                    server.billing_system
                }}</span>
                <span v-if="server.external_id"
                    >{{ $t('Service') }}
                    <span class="font-mono">{{
                        server.external_id
                    }}</span></span
                >
                <span v-if="server.external_client_id"
                    >{{ $t('Customer') }}
                    <span class="font-mono">{{
                        server.external_client_id
                    }}</span></span
                >
            </dd>
            <dd v-else class="text-muted-foreground">
                {{ $t('No billing system') }}
            </dd>
        </div>
        <div>
            <dt class="sr-only">{{ $t('Location') }}</dt>
            <dd>{{ server.location }}</dd>
        </div>
        <div class="text-xs text-muted-foreground">
            <dt class="sr-only">{{ $t('In Proxmox') }}</dt>
            <dd class="flex flex-wrap gap-x-2">
                <span class="font-mono">VMID {{ server.vmid }}</span>
                <span class="font-mono">{{ server.node }}</span>
                <span v-if="server.os_template">{{ server.os_template }}</span>
            </dd>
        </div>
    </dl>
</template>
