import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import Notice from '@/components/admin/Notice.vue';

describe('Notice', () => {
    it('shows a warning with its text by default', () => {
        const wrapper = mount(Notice, {
            slots: { default: 'Some changes take effect after a restart.' },
        });

        expect(wrapper.text()).toContain('Some changes take effect');
        expect(wrapper.classes()).toContain('bg-status-warning');
        expect(wrapper.find('svg').attributes('aria-hidden')).toBe('true');
    });

    it('shows something broken in the danger tone', () => {
        const wrapper = mount(Notice, {
            props: { tone: 'danger' },
            slots: { default: 'The VM was deleted outside the panel.' },
        });

        expect(wrapper.classes()).toContain('bg-status-danger');
        expect(wrapper.find('svg').classes()).toContain(
            'text-status-danger-text',
        );
    });

    it('passes attributes such as data-test through', () => {
        const wrapper = mount(Notice, {
            attrs: { 'data-test': 'reboot-required' },
            slots: { default: 'Restart' },
        });

        expect(wrapper.attributes('data-test')).toBe('reboot-required');
    });
});
