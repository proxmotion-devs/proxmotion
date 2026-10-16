import { usePage } from '@inertiajs/vue3';
import { useMounted } from '@vueuse/core';
import { formatDateTime } from '@/lib/format';

export type UseDateTimeReturn = {
    formatDateTime: (iso: string | null | undefined) => string;
};

/**
 * Formats dates in the page's locale. The server does not know the browser's
 * time zone, so the server-side render and hydration use UTC; the browser's
 * time zone takes over once the component has mounted.
 */
export function useDateTime(): UseDateTimeReturn {
    const page = usePage();
    const mounted = useMounted();

    return {
        formatDateTime: (iso) =>
            formatDateTime(
                iso,
                page.props.locale,
                mounted.value ? undefined : 'UTC',
            ),
    };
}
