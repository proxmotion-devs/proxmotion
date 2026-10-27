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
    planned_steps: ['configure', 'resize', 'addresses', 'apply'],
    created_at: null,
    ...overrides,
});

const render = (tasks: TaskProgress[]) =>
    mount(TaskList, {
        props: { tasks },
        global: { mocks: { $t: (key: string) => key } },
    });

const spinning = (tasks: TaskProgress[]) =>
    render(tasks)
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

    it('lists the planned steps of the server, also those still to come', () => {
        const rows = render([
            task({
                type: 'create',
                planned_steps: ['clone', 'wait_agent', 'guest_hostname'],
                steps: [done('clone')],
            }),
        ]).findAll('li');

        expect(rows.map((row) => row.text())).toEqual([
            'Clone template',
            'Wait for the guest agent',
            'Change the hostname in the server',
        ]);
    });

    it('names custom script steps by the script, with or without their event', () => {
        const rows = render([
            task({
                type: 'create',
                planned_steps: [
                    'guest_addresses',
                    'script:addresses_changed:Monitoring agent',
                    'script:provisioned:Monitoring agent',
                ],
                steps: [],
            }),
            task({
                uuid: 'task-2',
                type: 'guest_script',
                planned_steps: [],
                steps: [done('script:Clear cache')],
            }),
        ]).findAll('li');

        expect(rows.map((row) => row.text())).toEqual([
            'Configure additional IP addresses in the server',
            'Run the script Monitoring agent in the server',
            'Run the script Monitoring agent in the server',
            'Run the script Clear cache in the server',
        ]);
    });

    it('shows how long finished steps took', () => {
        const durations = render([
            task({
                steps: [
                    {
                        key: 'configure',
                        status: 'done',
                        message: null,
                        started_at: '2026-10-20T10:00:00+00:00',
                        finished_at: '2026-10-20T10:01:52+00:00',
                    },
                    {
                        key: 'resize',
                        status: 'done',
                        message: null,
                        started_at: '2026-10-20T10:01:52+00:00',
                        finished_at: '2026-10-20T10:01:56+00:00',
                    },
                    // Recorded before step times were kept.
                    done('addresses'),
                ],
            }),
        ])
            .findAll('[data-test="step-duration"]')
            .map((span) => span.text());

        expect(durations).toEqual(['1 min 52 s', '4 s']);
    });

    it('shows the estimate of a running task in whole minutes', () => {
        const estimate = (
            remaining_seconds: number,
            status: TaskProgress['status'] = 'running',
        ) =>
            render([
                task({ status, estimate: { remaining_seconds, based_on: 5 } }),
            ]).find('[data-test="task-estimate"]');

        expect(estimate(130).text()).toBe('About 3 min left');
        expect(estimate(30).text()).toBe('Less than a minute left');
        expect(estimate(130, 'failed').exists()).toBe(false);
        expect(
            render([task({ estimate: null })])
                .find('[data-test="task-estimate"]')
                .exists(),
        ).toBe(false);
    });
});
