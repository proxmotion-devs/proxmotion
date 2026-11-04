import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

type Action = Parameters<typeof router.visit>[0];
type Options = NonNullable<Parameters<typeof router.visit>[1]>;

/**
 * Buttons that send a request on their own (a check, a test, a retry): while
 * one runs, every button of the component is disabled and the one pressed
 * shows a spinner, so a slow answer from Proxmox or a receiver never gets a
 * second click that repeats the request.
 */
export function usePendingVisit() {
    const pending = ref<string | null>(null);

    const visit = (key: string, action: Action, options: Options = {}) => {
        if (pending.value !== null) {
            return;
        }

        router.visit(action, {
            preserveScroll: true,
            ...options,
            onStart: (event) => {
                pending.value = key;
                options.onStart?.(event);
            },
            onFinish: (event) => {
                pending.value = null;
                options.onFinish?.(event);
            },
        });
    };

    return {
        /** The key of the request that runs, if any. */
        pending,
        busy: (key: string) => pending.value === key,
        visit,
    };
}
