import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vite-plus/test';
import TaskList from '@/components/servers/TaskList.vue';
import type { TaskProgress } from '@/types/admin';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'en' } }),
}));

const task = (overrides: Partial<TaskProgress>): TaskProgress => ({
    uuid: 'task-1',
    type: 'change_resources',
    status: 'running',
    steps: [],
    created_at: null,
    ...overrides,
});

const spinning = (tasks: TaskProgress[]) =>
    mount(TaskList, {
        props: { tasks },
        global: { mocks: { $t: (key: string) => key } },
    })
        .findAll('li')
        .filter((row) => row.find('.animate-spin').exists())
        .map((row) => row.text());

const done = (key: string) => ({ key, status: 'done', message: null });

describe('TaskList', () => {
    it('spins only the step the pipeline marked as running', () => {
        expect(
            spinning([
                task({
                    steps: [
                        done('configure'),
                        done('resize'),
                        done('addresses'),
                        done('apply'),
                        { key: 'guest_disk', status: 'running', message: null },
                    ],
                }),
            ]),
        ).toEqual(['Grow the file system in the server']);
    });

    it('otherwise spins the first planned step that is not done', () => {
        expect(spinning([task({ steps: [done('configure')] })])).toEqual([
            'Grow disk',
        ]);
    });

    it('spins nothing once the task has finished', () => {
        expect(
            spinning([
                task({
                    status: 'succeeded',
                    steps: [
                        { key: 'guest_disk', status: 'running', message: null },
                    ],
                }),
            ]),
        ).toEqual([]);
    });
});
