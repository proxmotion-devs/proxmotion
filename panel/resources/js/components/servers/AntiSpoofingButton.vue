<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import type { RouteDefinition } from '@/wayfinder';

/**
 * Asks for confirmation, then turns anti-spoofing on (again) in the VM
 * firewall: IP and MAC filter, no router advertisements or DHCP server
 * replies, an IP set of exactly these addresses (docs/adr/0033).
 */
const props = defineProps<{
    action: RouteDefinition<'post'>;
    addresses: string[];
    disabled?: boolean;
}>();

const open = ref(false);
const processing = ref(false);

const confirm = () => {
    router.visit(props.action, {
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => {
            processing.value = false;
            open.value = false;
        },
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button
                variant="outline"
                size="sm"
                :disabled="disabled || addresses.length === 0"
            >
                <ShieldCheck class="h-4 w-4" />
                {{ $t('Enable anti-spoofing') }}
            </Button>
        </DialogTrigger>
        <DialogContent>
            <DialogTitle>{{ $t('Enable anti-spoofing?') }}</DialogTitle>
            <DialogDescription>
                {{
                    $t(
                        'The VM firewall lets the server use only these addresses and its own MAC address, and drops router advertisements and DHCP server replies it sends. An address missing here stops working for the server. Nothing runs inside the server.',
                    )
                }}
            </DialogDescription>
            <ul class="flex flex-col gap-1 font-mono text-xs">
                <li v-for="address in addresses" :key="address">
                    {{ address }}
                </li>
            </ul>
            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">{{ $t('Cancel') }}</Button>
                </DialogClose>
                <Button :disabled="processing" @click="confirm">
                    <Spinner v-if="processing" />
                    {{ $t('Enable anti-spoofing') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
