import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vite-plus/test';
import TaskAnnouncer from '@/components/servers/TaskAnnouncer.vue';
import type { TaskProgress } from '@/types/admin';

vi.mock('laravel-vue-i18n', () => ({
    // Replaces :placeholders like laravel-vue-i18n does.
    trans: (key: string, params: Record<string, string> = {}) =>
        Object.entries(params).reduce(
            (text, [name, value]) => text.replace(`:${name}`, value),
            key,
        ),
}));

const task = (overrides: Partial<TaskProgress>): TaskProgress => ({
    uuid: 'task-1',
    type: 'reinstall',
    status: 'running',
    steps: [],
    planned_steps: [],
    created_at: null,
    ...overrides,
});

const region = (wrapper: ReturnType<typeof mount>) =>
    wrapper.find('[data-test="task-announcer"]');

describe('TaskAnnouncer', () => {
    it('reads nothing out for the tasks the page opened with', () => {
        const wrapper = mount(TaskAnnouncer, {
            props: { tasks: [task({})] },
        });

        expect(region(wrapper).attributes('role')).toBe('status');
        expect(region(wrapper).text()).toBe('');
    });

    it('reads out a task that finished or started since', async () => {
        const wrapper = mount(TaskAnnouncer, {
            props: { tasks: [task({})] },
        });

        await wrapper.setProps({ tasks: [task({ status: 'failed' })] });
        expect(region(wrapper).text()).toBe('Reinstall: Failed.');

        await wrapper.setProps({
            tasks: [
                task({ uuid: 'task-2', type: 'reboot', status: 'queued' }),
                task({ status: 'failed' }),
            ],
        });
        expect(region(wrapper).text()).toBe('Reboot: Queued.');
    });
});
