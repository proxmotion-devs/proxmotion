import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vite-plus/test';
import { nextTick } from 'vue';
import TemplateGuide from '@/components/admin/TemplateGuide.vue';

const mountGuide = () =>
    mount(TemplateGuide, {
        global: { mocks: { $t: (key: string) => key } },
    });

describe('TemplateGuide', () => {
    afterEach(() => (window.location.hash = ''));

    it('starts with the Linux steps', () => {
        const wrapper = mountGuide();

        expect(
            wrapper.find('[data-test="template-panel-linux"]').exists(),
        ).toBe(true);
        expect(wrapper.text()).toContain('cloud-init clean --logs --seed');
    });

    it('switches to the Windows steps', async () => {
        const wrapper = mountGuide();

        await wrapper
            .find('[data-test="template-tab-windows"]')
            .trigger('click');

        expect(wrapper.text()).toContain('cloudbase-init-unattend.conf');
        expect(wrapper.text()).not.toContain('cloud-init clean --logs --seed');
    });

    it('explains macOS', async () => {
        const wrapper = mountGuide();

        await wrapper.find('[data-test="template-tab-macos"]').trigger('click');

        expect(wrapper.text()).toContain('Can I offer macOS servers?');
    });

    it('opens the tab a link points to', async () => {
        window.location.hash = '#template-guide-windows';

        const wrapper = mountGuide();
        await nextTick();

        expect(
            wrapper.find('[data-test="template-panel-windows"]').exists(),
        ).toBe(true);
    });
});
