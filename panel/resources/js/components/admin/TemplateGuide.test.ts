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
        // Every version from Server 2016 to Windows 11, with its driver folder
        expect(wrapper.text()).toContain('Server 2016');
        expect(wrapper.text()).toContain('Windows 11');
        expect(wrapper.text()).toContain('2k25');
        expect(wrapper.text()).not.toContain('cloud-init clean --logs --seed');
    });

    it('starts Windows with Sysprep and switches to the way without it', async () => {
        const wrapper = mountGuide();

        await wrapper
            .find('[data-test="template-tab-windows"]')
            .trigger('click');

        expect(
            wrapper.find('[data-test="template-way-panel-sysprep"]').exists(),
        ).toBe(true);
        expect(wrapper.text()).toContain('sysprep.exe /generalize');

        await wrapper
            .find('[data-test="template-way-nosysprep"]')
            .trigger('click');

        expect(
            wrapper.find('[data-test="template-way-panel-nosysprep"]').exists(),
        ).toBe(true);
        expect(wrapper.text()).toContain('SusClientId');
        expect(wrapper.text()).toContain('Remove-LocalUser -Name setup');
        expect(wrapper.text()).not.toContain('sysprep.exe /generalize');
    });

    it('switches to the FreeBSD steps', async () => {
        const wrapper = mountGuide();

        await wrapper
            .find('[data-test="template-tab-freebsd"]')
            .trigger('click');

        expect(
            wrapper.find('[data-test="template-panel-freebsd"]').exists(),
        ).toBe(true);
        // The settings without which clones do not grow or the panel does not wait for cloud-init
        expect(wrapper.text()).toContain('sysrc growfs_swap_size=0');
        expect(wrapper.text()).toContain('qemu_guest_agent_env=');
        expect(wrapper.text()).toContain('virtio_console_load=YES');
        expect(wrapper.text()).not.toContain('cloudbase-init-unattend.conf');
    });

    it('opens the FreeBSD tab a link points to', async () => {
        window.location.hash = '#template-guide-freebsd';

        const wrapper = mountGuide();
        await nextTick();

        expect(
            wrapper.find('[data-test="template-panel-freebsd"]').exists(),
        ).toBe(true);
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

    it('opens the Windows way a link points to', async () => {
        window.location.hash = '#template-guide-windows-nosysprep';

        const wrapper = mountGuide();
        await nextTick();

        expect(
            wrapper.find('[data-test="template-way-panel-nosysprep"]').exists(),
        ).toBe(true);
    });
});
