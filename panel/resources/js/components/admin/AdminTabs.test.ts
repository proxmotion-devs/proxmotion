import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import AdminTabs from '@/components/admin/AdminTabs.vue';

const tabs = [
    { value: 'details', label: 'Details' },
    { value: 'scripts', label: 'Scripts' },
    { value: 'versions', label: 'History' },
];

const render = (modelValue = 'scripts') =>
    mount(AdminTabs, {
        props: {
            tabs,
            modelValue,
            label: 'Sections',
            idPrefix: 'editor-tab',
            panelId: 'editor-panel',
        },
        global: { mocks: { $t: (key: string) => key } },
    });

describe('AdminTabs', () => {
    it('marks only the selected tab, in the tab order and linked to the panel', () => {
        const buttons = render().findAll('[role="tab"]');

        expect(buttons.map((tab) => tab.attributes('aria-selected'))).toEqual([
            'false',
            'true',
            'false',
        ]);
        expect(buttons.map((tab) => tab.attributes('tabindex'))).toEqual([
            '-1',
            '0',
            '-1',
        ]);
        expect(buttons[1].attributes('aria-controls')).toBe('editor-panel');
        expect(buttons[1].attributes('id')).toBe('editor-tab-scripts');
        // The underline is the foreground colour, never the provider's.
        expect(buttons[1].classes()).toContain('border-foreground');
        expect(buttons[1].classes()).not.toContain('border-primary');
    });

    it('moves with arrow keys, Home and End, and leaves the switch to the parent', async () => {
        const wrapper = render();
        const list = wrapper.find('[role="tablist"]');

        await list.trigger('keydown', { key: 'ArrowRight' });
        await list.trigger('keydown', { key: 'ArrowLeft' });
        await list.trigger('keydown', { key: 'Home' });
        await list.trigger('keydown', { key: 'End' });
        await wrapper.findAll('[role="tab"]')[0].trigger('click');

        expect(wrapper.emitted('select')).toEqual([
            ['versions'],
            ['details'],
            ['details'],
            ['versions'],
            ['details'],
        ]);
    });
});
