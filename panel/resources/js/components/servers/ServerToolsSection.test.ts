import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vite-plus/test';
import { nextTick } from 'vue';
import ServerToolsSection from '@/components/servers/ServerToolsSection.vue';

const mountTools = (attention = false) =>
    mount(ServerToolsSection, {
        props: { contents: ['Checks', 'Scripts', 'Move'], attention },
        slots: { default: '<p data-test="inside">Checks</p>' },
        global: { mocks: { $t: (key: string) => key } },
    });

const details = (wrapper: ReturnType<typeof mountTools>) =>
    wrapper.get('[data-test="server-tools"]').element as HTMLDetailsElement;

describe('ServerToolsSection', () => {
    beforeEach(() => localStorage.clear());

    it('starts folded and names what is inside', () => {
        const wrapper = mountTools();

        expect(details(wrapper).open).toBe(false);
        expect(wrapper.get('summary').text()).toContain(
            'Diagnostics and administration',
        );
        expect(wrapper.get('summary').text()).toContain(
            'Checks · Scripts · Move',
        );
    });

    it('opens by itself when something inside needs a look', async () => {
        const wrapper = mountTools();
        await wrapper.setProps({ attention: true });
        await nextTick();

        expect(details(wrapper).open).toBe(true);
        expect(
            mountTools(true).get('[data-test="server-tools"]').attributes(),
        ).toHaveProperty('open');
    });

    it('remembers that it was opened', async () => {
        const wrapper = mountTools();
        details(wrapper).open = true;
        await wrapper.get('[data-test="server-tools"]').trigger('toggle');

        expect(localStorage.getItem('proxmotion.server-tools')).toBe('open');
        expect(details(mountTools())).toHaveProperty('open', true);

        const again = mountTools();
        details(again).open = false;
        await again.get('[data-test="server-tools"]').trigger('toggle');
        expect(localStorage.getItem('proxmotion.server-tools')).toBeNull();
    });
});
