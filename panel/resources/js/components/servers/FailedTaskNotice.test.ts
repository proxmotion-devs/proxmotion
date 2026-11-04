import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vite-plus/test';
import FailedTaskNotice from '@/components/servers/FailedTaskNotice.vue';
import type { TaskProgress } from '@/types/admin';

const { visit } = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({ router: { visit } }));
vi.mock('laravel-vue-i18n', () => ({
    trans: (key: string, replace: Record<string, string> = {}) =>
        Object.entries(replace).reduce(
            (text, [name, value]) => text.replace(`:${name}`, value),
            key,
        ),
}));
vi.mock('@/composables/useDateTime', () => ({
    useDateTime: () => ({ formatDateTime: (iso: string) => iso }),
}));
// The rebuild dialog has its own tests; here only whether it is offered.
vi.mock('@/components/servers/RebuildDialog.vue', () => ({
    default: {
        name: 'RebuildDialog',
        template: '<button data-test="server-rebuild">Rebuild</button>',
    },
}));

const task = (overrides: Partial<TaskProgress> = {}): TaskProgress => ({
    uuid: 'task-1',
    type: 'create',
    status: 'failed',
    steps: [],
    error: 'clone failed: storage local-lvm is full',
    created_at: '2026-11-04T10:00:00Z',
    finished_at: '2026-11-04T10:02:00Z',
    ...overrides,
});

const retry = { url: '/admin/servers/s1/retry', method: 'post' as const };
const rebuild = { url: '/admin/servers/s1/rebuild', method: 'post' as const };

const render = (
    tasks: TaskProgress[],
    { canRetry = true, canRebuild = false } = {},
) =>
    mount(FailedTaskNotice, {
        props: {
            tasks,
            hostname: 'web1.example.com',
            canRetry,
            retry,
            rebuild: canRebuild ? rebuild : null,
        },
        global: { mocks: { $t: (key: string) => key } },
    });

describe('FailedTaskNotice', () => {
    it('says what failed, when, and the error', () => {
        const wrapper = render([task()]);

        expect(wrapper.text()).toContain('Creating the server failed.');
        expect(wrapper.find('[data-test="server-failure-time"]').text()).toBe(
            '2026-11-04T10:02:00Z',
        );
        expect(wrapper.find('[data-test="server-failure-error"]').text()).toBe(
            'clone failed: storage local-lvm is full',
        );
        expect(wrapper.text()).toContain(
            'Retry starts the creation again; what is already done is kept.',
        );
    });

    it('names the operation Retry runs again', () => {
        const wrapper = render([task({ type: 'reinstall' })]);

        expect(wrapper.text()).toContain('The reinstall failed.');
        expect(wrapper.text()).toContain(
            'Retry starts the reinstall again; what is already done is kept.',
        );
        expect(render([task({ type: 'terminate' })]).text()).toContain(
            'Deleting the server failed.',
        );
    });

    it('retries with a pending button', async () => {
        const wrapper = render([task()]);

        await wrapper.find('[data-test="server-retry"]').trigger('click');

        expect(visit).toHaveBeenCalledWith(retry, expect.any(Object));
    });

    it('offers Rebuild from scratch only when the server may be rebuilt', () => {
        expect(
            render([task()]).find('[data-test="server-rebuild"]').exists(),
        ).toBe(false);

        const wrapper = render([task()], { canRebuild: true });

        expect(wrapper.find('[data-test="server-rebuild"]').exists()).toBe(
            true,
        );
        expect(wrapper.text()).toContain(
            'Rebuild from scratch deletes that VM',
        );
    });

    it('opens the task on request', async () => {
        const wrapper = render([task()]);

        await wrapper
            .find('[data-test="server-failure-tasks"]')
            .trigger('click');

        expect(wrapper.emitted('showTasks')).toHaveLength(1);
    });

    it('explains a server left busy with no task running', () => {
        const wrapper = render([task({ status: 'succeeded', error: null })]);

        expect(wrapper.text()).toContain(
            'No task is running, but the server is still marked as busy.',
        );
        expect(
            wrapper.find('[data-test="server-failure-error"]').exists(),
        ).toBe(false);
        expect(
            wrapper.find('[data-test="server-failure-tasks"]').exists(),
        ).toBe(false);
    });
});
