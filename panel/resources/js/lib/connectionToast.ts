import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { toast } from 'vue-sonner';

/** Pages that poll would otherwise repeat it every few seconds. */
const QUIET_MS = 30_000;

/**
 * Says so when a visit or a page's polling cannot reach the panel at all
 * (offline, server down), which Inertia otherwise leaves silent. Shown at
 * most once every 30 seconds.
 */
export function initializeConnectionToast(now: () => number = Date.now): void {
    let shownAt = Number.NEGATIVE_INFINITY;

    router.on('networkError', () => {
        if (now() - shownAt < QUIET_MS) {
            return;
        }

        shownAt = now();
        toast.error(
            trans(
                'The panel could not be reached. Check your connection and try again.',
            ),
        );
    });
}
