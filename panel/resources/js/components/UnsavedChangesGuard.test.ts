import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import { defineComponent, h, nextTick, ref } from 'vue';
import UnsavedChangesGuard from '@/components/UnsavedChangesGuard.vue';
import { useUnsavedChanges } from '@/lib/unsavedChanges';
import type { LeavingVisit } from '@/lib/unsavedChanges';

const { handlers, visit } = vi.hoisted(() => ({
    handlers: {} as Record<string, (event: CustomEvent) => void>,
    visit: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    router: {
        on: (name: string, handler: (event: CustomEvent) => void) => {
            handlers[name] = handler;

            return () => delete handlers[name];
        },
        visit,
    },
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (key: string) => key }));

const ConfirmDialog = defineComponent({
    props: ['open', 'title', 'description', 'confirmLabel', 'destructive'],
    emits: ['confirm', 'update:open'],
    setup:
        (props, { emit }) =>
        () =>
            h(
                'div',
                { 'data-test': 'dialog', 'data-open': String(props.open) },
                [
                    h(
                        'button',
                        { onClick: () => emit('confirm') },
                        props.confirmLabel,
                    ),
                ],
            ),
});

const dirty = ref(false);
const Editor = defineComponent({
    setup: () => {
        useUnsavedChanges(() => dirty.value);

        return () => h('textarea');
    },
});

const mountAll = () => {
    const editor = mount(Editor);
    const guard = mount(UnsavedChangesGuard, {
        global: { stubs: { ConfirmDialog } },
    });

    return { editor, guard };
};

const before = (path: string, extra: Partial<LeavingVisit> = {}) => {
    const event = new CustomEvent('inertia:before', {
        cancelable: true,
        detail: {
            visit: {
                method: 'get',
                url: new URL(path, window.location.origin),
                only: [],
                except: [],
                ...extra,
            },
        },
    });
    handlers.before(event);

    return event;
};

describe('UnsavedChangesGuard', () => {
    afterEach(() => {
        dirty.value = false;
        visit.mockReset();
    });

    it('lets visits go while nothing is changed', () => {
        const { editor, guard } = mountAll();

        expect(before('/admin/servers').defaultPrevented).toBe(false);

        editor.unmount();
        guard.unmount();
    });

    it('stops a visit to another page and makes it once confirmed', async () => {
        const { editor, guard } = mountAll();
        dirty.value = true;

        expect(before('/admin/servers').defaultPrevented).toBe(true);
        await nextTick();
        expect(guard.get('[data-test="dialog"]').attributes('data-open')).toBe(
            'true',
        );

        await guard.get('button').trigger('click');
        expect(visit).toHaveBeenCalledWith(
            `${window.location.origin}/admin/servers`,
        );
        // The visit it makes itself goes through.
        expect(before('/admin/servers').defaultPrevented).toBe(false);

        editor.unmount();
        guard.unmount();
    });

    it('never stops submits, prefetches or polling', () => {
        const { editor, guard } = mountAll();
        dirty.value = true;

        expect(
            before('/admin/servers', { method: 'put' }).defaultPrevented,
        ).toBe(false);
        expect(
            before('/admin/servers', { prefetch: true }).defaultPrevented,
        ).toBe(false);
        expect(
            before('/admin/servers', { only: ['tasks'] }).defaultPrevented,
        ).toBe(false);
        expect(
            before(window.location.pathname + window.location.search)
                .defaultPrevented,
        ).toBe(false);

        editor.unmount();
        guard.unmount();
    });

    it('forgets a component once it is gone', () => {
        const { editor, guard } = mountAll();
        dirty.value = true;
        editor.unmount();

        expect(before('/admin/servers').defaultPrevented).toBe(false);

        guard.unmount();
    });

    it('asks the browser before the tab is closed with changes', () => {
        const { editor, guard } = mountAll();
        const event = new Event('beforeunload', { cancelable: true });

        window.dispatchEvent(event);
        expect(event.defaultPrevented).toBe(false);

        dirty.value = true;
        const changed = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(changed);
        expect(changed.defaultPrevented).toBe(true);

        editor.unmount();
        guard.unmount();
    });
});
