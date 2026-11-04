import { onBeforeUnmount, onMounted } from 'vue';

/** What the guard reads of an Inertia visit about to start. */
export type LeavingVisit = {
    method: string;
    url: URL;
    prefetch?: boolean;
    only: string[];
    except: string[];
};

const sources = new Set<() => boolean>();

/**
 * Marks a page or component as holding edits that are lost when the page is
 * left: while it is mounted and `dirty()` is true, `UnsavedChangesGuard`
 * asks before a visit to another page, and the browser before the tab is
 * closed or reloaded.
 */
export function useUnsavedChanges(dirty: () => boolean): void {
    onMounted(() => sources.add(dirty));
    onBeforeUnmount(() => sources.delete(dirty));
}

export function hasUnsavedChanges(): boolean {
    return [...sources].some((dirty) => dirty());
}

/**
 * Whether a visit replaces the page: not a form's own submit (it keeps or
 * leaves the page on purpose), a prefetch, a partial reload of polling, or a
 * reload of the same address.
 */
export function leavesPage(visit: LeavingVisit, current: URL): boolean {
    if (visit.method.toLowerCase() !== 'get' || visit.prefetch === true) {
        return false;
    }

    if (visit.only.length > 0 || visit.except.length > 0) {
        return false;
    }

    return (
        visit.url.pathname !== current.pathname ||
        visit.url.search !== current.search
    );
}
