<script setup lang="ts">
import AppSystemsPicker from '@/components/admin/AppSystemsPicker.vue';
import type { AppOsVersion } from '@/components/admin/AppSystemsPicker.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * The operating system versions of an app from the apps list
 * (docs/adr/0026): the same ticks as the editor, saved at once.
 */
defineProps<{
    app: {
        slug: string;
        name: string;
        platform: string;
        recipe_os: string[];
        suggested_ids: number[];
        os_version_ids: number[];
    };
    osVersions: AppOsVersion[];
}>();

const open = defineModel<boolean>('open', { required: true });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>{{
                    $t('Operating systems of the app')
                }}</DialogTitle>
                <DialogDescription>{{ app.name }}</DialogDescription>
            </DialogHeader>
            <p
                v-if="app.recipe_os.length"
                class="rounded-lg bg-muted/40 p-3 text-xs"
            >
                {{ $t('The recipe says:') }}
                <span class="font-mono">{{ app.recipe_os.join(', ') }}</span>
            </p>
            <div class="max-h-96 overflow-y-auto">
                <AppSystemsPicker :app="app" :os-versions="osVersions" />
            </div>
            <DialogFooter>
                <Button variant="outline" @click="open = false">{{
                    $t('Close')
                }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
