import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import { nextTick } from 'vue';
import GuideBox from '@/components/admin/GuideBox.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
}));

const mountGuide = (props: Record<string, string> = {}) =>
    mount(GuideBox, {
        props: { id: 'apps', title: 'How to add an app', ...props },
        slots: { default: '<p>Step one</p>' },
        global: {
            mocks: {
                $t: (key: string, replace?: Record<string, string>) =>
                    replace ? key.replace(':title', replace.title) : key,
            },
        },
    });

describe('GuideBox', () => {
    beforeEach(() => localStorage.clear());

    it('shows the title, the steps and a link to its Help topic', () => {
        const wrapper = mountGuide({ topic: 'apps' });

        expect(wrapper.text()).toContain('How to add an app');
        expect(wrapper.text()).toContain('Step one');
        expect(wrapper.find('a').attributes('href')).toBe('/admin/help#apps');
    });

    it('stays hidden after it was hidden once', async () => {
        const wrapper = mountGuide();
        await wrapper.find('[data-test="guide-hide"]').trigger('click');

        expect(wrapper.text()).not.toContain('Step one');
        expect(wrapper.text()).toContain('Show guide: How to add an app');

        const again = mountGuide();
        await nextTick();

        expect(again.find('[data-test="guide-apps"]').exists()).toBe(false);
        expect(localStorage.getItem('proxmotion.guide.apps')).toBe('hidden');
    });

    it('can be shown again', async () => {
        localStorage.setItem('proxmotion.guide.apps', 'hidden');
        const wrapper = mountGuide();
        await nextTick();

        await wrapper.find('[data-test="guide-show"]').trigger('click');

        expect(wrapper.text()).toContain('Step one');
        expect(localStorage.getItem('proxmotion.guide.apps')).toBeNull();
    });

    it('keeps other guides open', async () => {
        localStorage.setItem('proxmotion.guide.apps', 'hidden');
        const wrapper = mountGuide({ id: 'os-templates' });
        await nextTick();

        expect(wrapper.text()).toContain('Step one');
    });
});
