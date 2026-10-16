<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { KeyRound } from '@lucide/vue';
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
 * Asks for confirmation, then starts a root password reset.
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
                <KeyRound class="h-4 w-4" />
                {{ $t('Reset root password') }}
            </Button>
        </DialogTrigger>
        <DialogContent>
            <DialogTitle>{{ $t('Set a new root password?') }}</DialogTitle>
            <DialogDescription>
                {{
                    $t(
                        'A new random password is set inside the running server and the current one stops working. You can see the new password once on this page.',
                    )
                }}
            </DialogDescription>
            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">{{ $t('Cancel') }}</Button>
                </DialogClose>
                <Button :disabled="processing" @click="confirm">
                    <Spinner v-if="processing" />
                    {{ $t('Reset root password') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
