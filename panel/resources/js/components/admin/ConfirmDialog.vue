<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * A yes/no question in the panel's own dialog, instead of the browser's
 * `window.confirm()`, which cannot be styled or translated. The caller opens
 * it and acts on `confirm`. Title and labels are English keys, translated
 * here; the description is passed translated, since it usually carries
 * placeholders.
 */
defineProps<{
    title: string;
    description: string;
    confirmLabel: string;
    destructive?: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ confirm: [] }>();

// Emitted before closing, so a caller that keeps its pending value in the
// open state still has it.
const confirm = () => {
    emit('confirm');
    open.value = false;
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <DialogTitle>{{ $t(title) }}</DialogTitle>
            <DialogDescription>{{ description }}</DialogDescription>
            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">{{ $t('Cancel') }}</Button>
                </DialogClose>
                <Button
                    :variant="destructive ? 'destructive' : 'default'"
                    data-test="confirm-dialog-confirm"
                    @click="confirm"
                >
                    {{ $t(confirmLabel) }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
