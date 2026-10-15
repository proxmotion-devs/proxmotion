import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { Ref } from 'vue';
import { sync } from '@/routes/admin/clusters';

/** Seconds after starting a sync at which the page data is reloaded. */
const RELOAD_AFTER = [3, 8, 15];

/**
 * Starts a (queued) cluster sync and reloads the page data a few times
 * afterwards, so the result shows up without a manual refresh.
 */
export function useClusterSync(): {
    syncing: Ref<string | null>;
    startSync: (uuid: string) => void;
} {
    const syncing = ref<string | null>(null);

    const startSync = (uuid: string) => {
        router.visit(sync(uuid), {
            preserveScroll: true,
            onStart: () => (syncing.value = uuid),
            onSuccess: () => {
                RELOAD_AFTER.forEach((seconds, index) =>
                    setTimeout(() => {
                        router.reload({
                            onFinish: () => {
                                if (index === RELOAD_AFTER.length - 1) {
                                    syncing.value = null;
                                }
                            },
                        });
                    }, seconds * 1000),
                );
            },
            onError: () => (syncing.value = null),
        });
    };

    return { syncing, startSync };
}
