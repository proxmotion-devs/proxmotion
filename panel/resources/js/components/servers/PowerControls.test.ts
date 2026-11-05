import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vite-plus/test';
import { nextTick } from 'vue';
import PowerControls from '@/components/servers/PowerControls.vue';

vi.mock('@inertiajs/vue3', () => ({ router: { visit: vi.fn() } }));

const render = (power: string | null | undefined) =>
    mount(PowerControls, {
        props: {
            power,
            busy: false,
            action: () => ({ url: '/power', method: 'post' as const }),
            hostname: 'web1.example.com',
        },
        global: { mocks: { $t: (key: string) => key } },
        attachTo: document.body,
    });

const buttons = (power: string | null | undefined) =>
    render(power)
        .findAll('button')
        .map((button) => button.text());

describe('PowerControls', () => {
    it('shows the usual action as a button and the rest in a menu', () => {
        // Shut down and Stop are in the menu behind "More".
        expect(buttons('running')).toEqual(['Reboot', 'More']);
        expect(buttons('stopped')).toEqual(['Start']);
    });

    it('offers Suspend in the menu only when asked to, and leaves it to the page', async () => {
        const plain = render('stopped');
        expect(plain.find('[data-test="power-menu"]').exists()).toBe(false);

        const wrapper = mount(PowerControls, {
            props: {
                power: 'stopped',
                busy: false,
                action: () => ({ url: '/power', method: 'post' as const }),
                canSuspend: true,
            },
            global: { mocks: { $t: (key: string) => key } },
            attachTo: document.body,
        });
        // A stopped server has nothing else in the menu; Suspend still needs a way in.
        expect(wrapper.find('[data-test="power-menu"]').exists()).toBe(true);
        wrapper.unmount();
    });

    it('offers everything while the state is not known', () => {
        expect(buttons(null)).toEqual(['Start', 'Reboot', 'More']);
    });

    it('names the server in the confirmation', async () => {
        const wrapper = render('running');
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Reboot')!
            .trigger('click');
        await nextTick();

        expect(
            document.body
                .querySelector('[data-test="power-confirm-hostname"]')
                ?.textContent?.trim(),
        ).toBe('web1.example.com');
        wrapper.unmount();
        document.body.innerHTML = '';
    });
});
