import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vite-plus/test';
import PowerControls from '@/components/servers/PowerControls.vue';

vi.mock('@inertiajs/vue3', () => ({ router: { visit: vi.fn() } }));

const render = (power: string | null | undefined) =>
    mount(PowerControls, {
        props: {
            power,
            busy: false,
            action: () => ({ url: '/power', method: 'post' as const }),
        },
        global: { mocks: { $t: (key: string) => key } },
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

    it('words the power state apart from the panel status', () => {
        expect(render('running').text()).toContain('Powered on');
        expect(render('stopped').text()).toContain('Powered off');
    });

    it('offers everything while the state is not known', () => {
        expect(buttons(null)).toEqual(['Start', 'Reboot', 'More']);
        expect(render('unknown').text()).toContain('Power state unknown');
    });
});
