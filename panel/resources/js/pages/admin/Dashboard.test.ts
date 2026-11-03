import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vite-plus/test';
import Dashboard from '@/pages/admin/Dashboard.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: {
        props: ['href'],
        template: '<a :href="href?.url ?? href"><slot /></a>',
    },
    usePage: () => ({ props: { locale: 'en' } }),
}));

const stats = {
    locations: 2,
    clusters: 1,
    clusters_failing: 0,
    nodes: 3,
    nodes_online: 3,
    ip_addresses: 254,
    ip_addresses_reserved: 4,
    os_templates: 5,
    packages: 6,
    drifts: 0,
    storage_problems: 0,
};

const servers = { total: 10, active: 8, suspended: 1, busy: 1, error: 0 };

const mountDashboard = (props: Record<string, unknown> = {}) =>
    mount(Dashboard, {
        props: {
            stats,
            servers,
            usageViolations: [],
            usageViolationCount: 0,
            failedTasks: [],
            failedTaskCount: 0,
            ...props,
        },
        global: {
            mocks: {
                $t: (key: string, replace?: Record<string, string>) =>
                    replace
                        ? Object.entries(replace).reduce(
                              (text, [name, value]) =>
                                  text.replace(`:${name}`, value),
                              key,
                          )
                        : key,
            },
            stubs: { GuideBox: true },
        },
    });

describe('Dashboard', () => {
    it('says nothing needs attention when there are no problems', () => {
        const wrapper = mountDashboard();

        expect(wrapper.find('[data-test="attention-clear"]').exists()).toBe(
            true,
        );
        expect(wrapper.findAll('[data-test^="attention-"]')).toHaveLength(1);
    });

    it('lists only the problems that have a count', () => {
        const wrapper = mountDashboard({
            stats: { ...stats, drifts: 2, nodes_online: 2 },
            servers: { ...servers, error: 1 },
        });

        expect(wrapper.find('[data-test="attention-clear"]').exists()).toBe(
            false,
        );
        expect(wrapper.find('[data-test="attention-drift"]').text()).toContain(
            '2',
        );
        expect(
            wrapper.find('[data-test="attention-nodes-offline"]').text(),
        ).toContain('1');
        expect(
            wrapper.find('[data-test="attention-servers-error"]').exists(),
        ).toBe(true);
        expect(wrapper.find('[data-test="attention-storage"]').exists()).toBe(
            false,
        );
    });

    it('shows failed tasks with their server, type and error', () => {
        const wrapper = mountDashboard({
            failedTasks: [
                {
                    uuid: 'task-1',
                    type: 'reinstall',
                    error: 'The guest agent did not answer.',
                    finished_at: '2026-11-03T10:00:00Z',
                    server: { uuid: 'server-1', hostname: 'vps1.example.com' },
                },
            ],
            failedTaskCount: 3,
        });

        const tasks = wrapper.find('[data-test="failed-tasks"]');
        expect(tasks.text()).toContain('vps1.example.com');
        expect(tasks.text()).toContain('Reinstall');
        expect(tasks.text()).toContain('The guest agent did not answer.');
        expect(tasks.text()).toContain('And 2 more.');
        expect(
            wrapper.find('[data-test="attention-failed-tasks"]').text(),
        ).toContain('3');
    });

    it('links the server counts to the filtered server list', () => {
        const wrapper = mountDashboard();
        const links = wrapper
            .find('[data-test="server-stats"]')
            .findAll('a')
            .map((link) => link.attributes('href'));

        expect(links).toContain('/admin/servers?status=suspended');
        expect(links).toContain('/admin/servers?status=error');
    });
});
