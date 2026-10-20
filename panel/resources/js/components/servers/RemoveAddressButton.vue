<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
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
 * Removes one additional address of a server after confirmation
 * (docs/adr/0028): the same request as the edit dialog, with only this address.
 */
const props = defineProps<{
    action: RouteDefinition<'post'>;
    address: string;
    disabled?: boolean;
}>();

const open = ref(false);
const form = useForm({ remove: [props.address] });
// The server reports why it refused under "addresses".
const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

const confirm = () =>
    form.submit(props.action, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
</script>

<template>
    <Dialog
        v-model:open="open"
        @update:open="(value: boolean) => value && form.clearErrors()"
    >
        <DialogTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="h-6 w-6 text-destructive hover:bg-destructive/10 hover:text-destructive"
                :disabled="disabled"
                :aria-label="$t('Remove :address', { address })"
                :title="$t('Remove :address', { address })"
            >
                <Trash2 class="h-3.5 w-3.5" />
            </Button>
        </DialogTrigger>
        <DialogContent>
            <DialogTitle>{{ $t('Remove :address?', { address }) }}</DialogTitle>
            <DialogDescription>
                {{
                    $t(
                        'The address is removed inside the server and from its firewall, released back to its pool, and its reverse DNS record is removed.',
                    )
                }}
            </DialogDescription>
            <p v-if="errors.addresses" class="text-sm text-destructive">
                {{ errors.addresses }}
            </p>
            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">{{ $t('Cancel') }}</Button>
                </DialogClose>
                <Button
                    variant="destructive"
                    :disabled="form.processing"
                    @click="confirm"
                >
                    <Spinner v-if="form.processing" />
                    {{ $t('Remove') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
