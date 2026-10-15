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
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{ submit: [] }>();
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
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

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        @click="open = false"
                    >
                        {{ $t('Cancel') }}
                    </Button>
                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" />
                        {{ $t(submitLabel ?? 'Save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
