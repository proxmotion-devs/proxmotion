<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

defineProps<{
    title: string;
    description?: string;
    processing?: boolean;
    submitLabel?: string;
    /** For forms with tables, e.g. firewall rules. */
    wide?: boolean;
    /** The submit button deletes or wipes something: shown in red. */
    destructive?: boolean;
    /** Keeps the submit button disabled, e.g. until a confirmation is typed. */
    submitDisabled?: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{ submit: [] }>();
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="max-h-[90dvh] overflow-y-auto"
            :class="wide ? 'sm:max-w-4xl' : 'sm:max-w-xl'"
        >
            <form class="grid gap-6" @submit.prevent="emit('submit')">
                <DialogHeader>
                    <DialogTitle>{{ $t(title) }}</DialogTitle>
                    <DialogDescription v-if="description">
                        {{ $t(description) }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4">
                    <slot />
                </div>

                <DialogFooter class="dialog-sticky-footer gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        @click="open = false"
                    >
                        {{ $t('Cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :variant="destructive ? 'destructive' : 'default'"
                        :disabled="processing || submitDisabled"
                    >
                        <Spinner v-if="processing" />
                        {{ $t(submitLabel ?? 'Save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
