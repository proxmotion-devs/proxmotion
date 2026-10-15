<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
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

const props = defineProps<{
    /** Wayfinder route of the DELETE endpoint. */
    action: RouteDefinition<'delete'>;
    /** Name of the thing being deleted, shown in the question. */
    name: string;
    description?: string;
    /** Show "Delete" next to the icon (for page headers). */
    withLabel?: boolean;
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
                v-if="withLabel"
                variant="outline"
                class="text-destructive hover:bg-destructive/10 hover:text-destructive"
            >
                <Trash2 class="h-4 w-4" />
                {{ $t('Delete') }}
            </Button>
            <Button
                v-else
                variant="ghost"
                size="icon"
                class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                :aria-label="$t('Delete :name', { name })"
                :title="$t('Delete :name', { name })"
            >
                <Trash2 class="h-4 w-4" />
            </Button>
        </DialogTrigger>
        <DialogContent>
            <DialogTitle>{{ $t('Delete :name?', { name }) }}</DialogTitle>
            <DialogDescription>
                {{ $t(description ?? 'This cannot be undone.') }}
            </DialogDescription>
            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">{{ $t('Cancel') }}</Button>
                </DialogClose>
                <Button
                    variant="destructive"
                    :disabled="processing"
                    @click="confirm"
                >
                    <Spinner v-if="processing" />
                    {{ $t('Delete') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
