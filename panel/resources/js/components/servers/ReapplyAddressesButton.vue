<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { RefreshCw } from '@lucide/vue';
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
 * Asks for confirmation, then applies the server's addresses again: the
 * firewall IP set and the address script inside the server (docs/adr/0028 §3).
 */
const props = defineProps<{
    action: RouteDefinition<'post'>;
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
            <Button variant="outline" size="sm" :disabled="disabled">
                <RefreshCw class="h-4 w-4" />
                {{ $t('Apply again') }}
            </Button>
        </DialogTrigger>
        <DialogContent>
            <DialogTitle>{{ $t('Apply the IP addresses again?') }}</DialogTitle>
            <DialogDescription>
                {{
                    $t(
                        'The firewall allows exactly the addresses listed here, and they are configured inside the server again through the guest agent. Use it when the server has lost an address. Nothing changes in the panel.',
                    )
                }}
            </DialogDescription>
            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">{{ $t('Cancel') }}</Button>
                </DialogClose>
                <Button :disabled="processing" @click="confirm">
                    <Spinner v-if="processing" />
                    {{ $t('Apply again') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
