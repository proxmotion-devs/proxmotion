import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vite-plus/test';
import Pagination from '@/components/admin/Pagination.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
}));

const links = [
    { url: '/admin/servers?page=1', label: '&laquo; Previous', active: false },
    { url: '/admin/servers?page=1', label: '1', active: false },
    { url: null, label: '2', active: true },
    { url: '/admin/servers?page=3', label: '3', active: false },
    { url: null, label: '...', active: false },
    { url: '/admin/servers?page=3', label: 'Next &raquo;', active: false },
];

const mountPagination = (
    paginator: Record<string, unknown> = {
        links,
        last_page: 3,
        current_page: 2,
    },
) =>
    mount(Pagination, {
        props: { paginator: paginator as never },
        global: {
            mocks: {
                $t: (key: string, replace?: Record<string, string>) =>
                    replace ? key.replace(':page', replace.page) : key,
            },
        },
    });

describe('Pagination', () => {
    it('renders its own previous and next labels, never the HTML ones', () => {
        const wrapper = mountPagination();

        expect(wrapper.text()).toContain('Previous');
        expect(wrapper.text()).toContain('Next');
        expect(wrapper.html()).not.toContain('&laquo;');
        expect(wrapper.html()).not.toContain('laquo');
    });

    it('marks the current page and links the others', () => {
        const wrapper = mountPagination();

        expect(wrapper.find('[aria-current="page"]').text()).toBe('2');
        expect(wrapper.find('a[aria-label="Page 3"]').exists()).toBe(true);
        expect(wrapper.findAll('a').map((a) => a.attributes('href'))).toEqual([
            '/admin/servers?page=1',
            '/admin/servers?page=1',
            '/admin/servers?page=3',
            '/admin/servers?page=3',
        ]);
    });

    it('disables previous on the first page', () => {
        const wrapper = mountPagination({
            links: [
                { url: null, label: '&laquo; Previous', active: false },
                { url: null, label: '1', active: true },
                { url: '/admin/servers?page=2', label: '2', active: false },
                {
                    url: '/admin/servers?page=2',
                    label: 'Next &raquo;',
                    active: false,
                },
            ],
            last_page: 2,
            current_page: 1,
        });

        expect(wrapper.find('[aria-disabled="true"]').text()).toContain(
            'Previous',
        );
    });

    it('renders nothing with a single page', () => {
        const wrapper = mountPagination({
            links: links.slice(0, 3),
            last_page: 1,
            current_page: 1,
        });

        expect(wrapper.find('nav').exists()).toBe(false);
    });
});
