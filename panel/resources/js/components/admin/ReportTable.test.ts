import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vite-plus/test';
import ReportTable from '@/components/admin/ReportTable.vue';
import type { ReportTable as ReportTableData } from '@/types/admin';

vi.mock('laravel-vue-i18n', () => ({
    trans: (key: string, replace: Record<string, string> = {}) =>
        Object.entries(replace).reduce(
            (text, [name, value]) => text.replace(`:${name}`, value),
            key,
        ),
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'en' } }),
    Link: {
        props: ['href'],
        template: '<a :href="href.url ?? href"><slot /></a>',
    },
}));

const table: ReportTableData = {
    key: 'servers',
    title: 'Servers',
    description: 'Traffic of each server.',
    columns: [
        { key: 'server', label: 'Server', type: 'server' },
        { key: 'status', label: 'Status', type: 'server_status' },
        { key: 'type', label: 'Task', type: 'task_type' },
        { key: 'memory', label: 'Memory', type: 'mib' },
        { key: 'traffic', label: 'Traffic', type: 'traffic' },
        { key: 'quota', label: 'Quota', type: 'traffic' },
        { key: 'share', label: 'Share', type: 'percent' },
        { key: 'took', label: 'Took', type: 'duration' },
        { key: 'value', label: 'Value', type: 'usage' },
        { key: 'active', label: 'Active', type: 'boolean' },
    ],
    rows: [
        {
            server: 'web.example.com',
            server_uuid: 'srv-1',
            status: 'suspended',
            type: 'reset_password',
            memory: 4096,
            traffic: 1_500_000_000,
            quota: null,
            share: 12.5,
            took: 3_900,
            value: 95,
            unit: 'percent',
            active: false,
        },
    ],
    row_count: 250,
    totals: { server: 'Total', memory: 4096 },
};

const mountTable = () =>
    mount(ReportTable, {
        props: { table, csvHref: '/admin/reports/traffic/servers.csv' },
        global: {
            mocks: {
                $t: (key: string, replace: Record<string, string> = {}) =>
                    Object.entries(replace).reduce(
                        (text, [name, value]) =>
                            text.replace(`:${name}`, value),
                        key,
                    ),
            },
        },
    });

describe('ReportTable', () => {
    it('formats cells by their column type', () => {
        const cells = mountTable()
            .findAll('tbody td')
            .map((cell) => cell.text());

        expect(cells).toEqual([
            'web.example.com',
            'Suspended',
            'Reset root password',
            '4 GiB',
            '1.5 GB',
            '∞',
            '12.5%',
            '1 h 5 min',
            '95%',
            'No',
        ]);
    });

    it('links servers to their page', () => {
        const link = mountTable().find('tbody a');

        expect(link.attributes('href')).toBe('/admin/servers/srv-1');
    });

    it('shows the totals row and the CSV download', () => {
        const wrapper = mountTable();

        expect(wrapper.find('tfoot').text()).toContain('Total');
        expect(wrapper.find('tfoot').text()).toContain('4 GiB');
        expect(wrapper.find('a[download]').attributes('href')).toBe(
            '/admin/reports/traffic/servers.csv',
        );
        expect(wrapper.text()).toContain(
            'Showing the first 1 of 250 rows; the CSV has all of them.',
        );
    });
});
