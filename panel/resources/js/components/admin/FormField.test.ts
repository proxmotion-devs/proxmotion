import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import { nextTick } from 'vue';
import FormField from '@/components/admin/FormField.vue';

const mountField = (props: Record<string, string | undefined> = {}) =>
    mount(FormField, {
        props: { label: 'Hostname', for: 'hostname', ...props },
        slots: { default: '<input id="hostname" />' },
        global: { mocks: { $t: (key: string) => key } },
        attachTo: document.body,
    });

describe('FormField', () => {
    it('links the control to its hint', () => {
        const wrapper = mountField({ hint: 'A fully qualified name.' });
        const input = wrapper.find('input');
        const hint = wrapper.find('p');

        expect(input.attributes('aria-describedby')).toBe(
            hint.attributes('id'),
        );
        expect(input.attributes('aria-invalid')).toBeUndefined();
        wrapper.unmount();
    });

    it('marks the control invalid while there is an error', async () => {
        const wrapper = mountField({ hint: 'A fully qualified name.' });
        await wrapper.setProps({ error: 'The hostname is taken.' });
        await nextTick();

        const input = wrapper.find('input');
        expect(input.attributes('aria-invalid')).toBe('true');
        expect(input.attributes('aria-describedby')?.split(' ')).toHaveLength(
            2,
        );
        expect(wrapper.text()).toContain('The hostname is taken.');

        await wrapper.setProps({ error: undefined });
        await nextTick();
        expect(input.attributes('aria-invalid')).toBeUndefined();
        wrapper.unmount();
    });

    it('finds the first control when no id is given', () => {
        const wrapper = mount(FormField, {
            props: { label: 'Notes', error: 'Too long.' },
            slots: { default: '<textarea></textarea>' },
            global: { mocks: { $t: (key: string) => key } },
        });

        expect(wrapper.find('textarea').attributes('aria-invalid')).toBe(
            'true',
        );
    });
});
