import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import PowerStateBadge from '@/components/servers/PowerStateBadge.vue';

const render = (power: string | null | undefined) =>
    mount(PowerStateBadge, {
        props: { power },
        global: { mocks: { $t: (key: string) => key } },
    });

describe('PowerStateBadge', () => {
    it('words the power state apart from the panel status', () => {
        expect(render('running').text()).toBe('Powered on');
        expect(render('stopped').text()).toBe('Powered off');
        expect(render('unknown').text()).toBe('Power state unknown');
        expect(render(null).text()).toBe('Power state unknown');
        expect(render(undefined).text()).toBe('Checking…');
    });

    it('reads a new power state out politely', async () => {
        const wrapper = render('stopped');
        const state = wrapper.find('[data-test="power-state"]');

        expect(state.attributes('role')).toBe('status');
        expect(state.attributes('aria-live')).toBe('polite');
        await wrapper.setProps({ power: 'running' });
        expect(state.text()).toBe('Powered on');
    });
});
