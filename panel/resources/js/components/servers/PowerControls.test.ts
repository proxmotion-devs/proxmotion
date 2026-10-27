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
    it('offers the actions that fit the power state', () => {
        expect(buttons('running')).toEqual(['Shut down', 'Reboot', 'Stop']);
        expect(buttons('stopped')).toEqual(['Start']);
        expect(buttons(null)).toHaveLength(4);
    });

    it('offers nothing for a VM Proxmox does not have', () => {
        const wrapper = render('missing');

        expect(wrapper.findAll('button')).toHaveLength(0);
        expect(wrapper.text()).toContain('Not found in Proxmox');
    });
});
