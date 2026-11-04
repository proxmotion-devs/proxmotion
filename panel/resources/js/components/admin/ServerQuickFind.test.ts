import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import ServerQuickFind from '@/components/admin/ServerQuickFind.vue';

const { visit } = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    router: { visit },
    Link: {
        props: ['href'],
        template: '<a :href="href?.url ?? href"><slot /></a>',
    },
}));

const mountFind = () =>
    mount(ServerQuickFind, {
        global: { mocks: { $t: (key: string) => key } },
        attachTo: document.body,
    });

describe('ServerQuickFind', () => {
    afterEach(() => {
        visit.mockClear();
        document.body.innerHTML = '';
    });

    it('opens the server list searched for what was typed', async () => {
        const wrapper = mountFind();
        await wrapper.find('input').setValue('  203.0.113.45 ');
        await wrapper.find('form').trigger('submit');

        expect(visit).toHaveBeenCalledOnce();
        expect(visit.mock.calls[0][0].url).toBe(
            '/admin/servers?search=203.0.113.45',
        );
        wrapper.unmount();
    });

    it('opens the whole list when nothing was typed', async () => {
        const wrapper = mountFind();
        await wrapper.find('form').trigger('submit');

        expect(visit.mock.calls[0][0].url).toBe('/admin/servers');
        wrapper.unmount();
    });

    it('opens a search box in a sheet on phones', async () => {
        const wrapper = mountFind();
        await wrapper
            .find('[data-test="server-quick-find-phone"]')
            .trigger('click');
        await flushPromises();

        const input = document.querySelector<HTMLInputElement>(
            '[data-test="server-quick-find-phone-input"]',
        );
        expect(input).not.toBeNull();
        expect(document.activeElement).toBe(input);

        input!.value = 'web1';
        input!.dispatchEvent(new Event('input'));
        input!.form!.dispatchEvent(new Event('submit'));

        expect(visit.mock.calls[0][0].url).toBe('/admin/servers?search=web1');
        wrapper.unmount();
    });

    it('takes focus on Ctrl+K', () => {
        const wrapper = mountFind();
        window.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'k', ctrlKey: true }),
        );

        expect(document.activeElement).toBe(wrapper.find('input').element);
        wrapper.unmount();
    });
});
