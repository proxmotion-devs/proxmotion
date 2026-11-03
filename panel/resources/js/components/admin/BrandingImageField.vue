<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ImageUp, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { destroy, store } from '@/routes/admin/settings/branding';

/**
 * One uploaded image of Panel settings > Branding (docs/adr/0053), uploaded
 * at once when chosen, outside the page's form.
 */
const props = defineProps<{
    image: 'logo' | 'logo_dark' | 'favicon';
    /** English keys, translated here. */
    label: string;
    hint: string;
    /** The image in use, or null. */
    url: string | null;
    /** Shown on a dark background, e.g. the dark-mode logo. */
    dark?: boolean;
}>();

const input = ref<HTMLInputElement>();
const processing = ref(false);
const error = ref<string | null>(null);

// preserveState: the page's form may hold unsaved changes; the images come from the shared props.
const options = {
    preserveScroll: true,
    preserveState: true,
    onStart: () => (processing.value = true),
    onFinish: () => (processing.value = false),
    onSuccess: () => (error.value = null),
    onError: (errors: Record<string, string>) =>
        (error.value = errors.file ?? null),
};

const upload = (event: Event) => {
    const target = event.target as HTMLInputElement;
    const file = target.files?.item(0) ?? null;
    target.value = '';

    if (file !== null) {
        router.post(
            store(props.image),
            { file },
            { ...options, forceFormData: true },
        );
    }
};

const remove = () => router.visit(destroy(props.image), options);
</script>

<template>
    <div class="flex items-start gap-4" :data-test="`branding-${image}`">
        <div
            class="flex size-16 shrink-0 items-center justify-center rounded-md border"
            :class="dark ? 'bg-neutral-950' : 'bg-white'"
        >
            <img
                v-if="url"
                :src="url"
                :alt="$t(label)"
                class="size-12 object-contain"
            />
            <span v-else class="text-xs text-muted-foreground">—</span>
        </div>
        <div class="grid flex-1 gap-2">
            <div>
                <p class="text-sm font-medium">{{ $t(label) }}</p>
                <p class="text-xs text-muted-foreground">{{ $t(hint) }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <input
                    ref="input"
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    class="hidden"
                    :data-test="`branding-${image}-file`"
                    @change="upload"
                />
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="processing"
                    @click="input?.click()"
                >
                    <ImageUp class="h-4 w-4" />
                    {{ url ? $t('Replace') : $t('Upload') }}
                </Button>
                <Button
                    v-if="url"
                    type="button"
                    size="sm"
                    variant="ghost"
                    :disabled="processing"
                    :data-test="`branding-${image}-remove`"
                    @click="remove"
                >
                    <Trash2 class="h-4 w-4" />
                    {{ $t('Remove') }}
                </Button>
            </div>
            <p v-if="error" class="text-sm text-destructive" role="alert">
                {{ error }}
            </p>
        </div>
    </div>
</template>
