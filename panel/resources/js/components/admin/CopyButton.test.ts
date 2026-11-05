import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import CopyButton from '@/components/admin/CopyButton.vue';

const render = () =>
    mount(CopyButton, {
        props: { value: '203.0.113.5', label: 'Copy IP address' },
        global: { mocks: { $t: (key: string) => key } },
    });

describe('CopyButton', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('copies the value and says so politely', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('navigator', { clipboard: { writeText } });
        const wrapper = render();
        const button = wrapper.find('[data-test="copy-button"]');

        expect(button.attributes('aria-label')).toBe('Copy IP address');
        await button.trigger('click');
        await flushPromises();

        expect(writeText).toHaveBeenCalledWith('203.0.113.5');
        expect(wrapper.find('[role="status"]').text()).toBe('Copied');
        expect(button.attributes('title')).toBe('Copied');
    });

    it('says nothing when the clipboard refused', async () => {
        vi.stubGlobal('navigator', {
            clipboard: { writeText: vi.fn().mockRejectedValue(new Error()) },
        });
        const wrapper = render();

        await wrapper.find('[data-test="copy-button"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[role="status"]').text()).toBe('');
    });

    it('is left out where the browser has no clipboard', () => {
        vi.stubGlobal('navigator', {});

        expect(render().find('[data-test="copy-button"]').exists()).toBe(false);
    });
});
