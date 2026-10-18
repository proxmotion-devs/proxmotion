import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vite-plus/test';
import { nextTick } from 'vue';
import HelpQuestion from '@/components/admin/HelpQuestion.vue';

const mountQuestion = () =>
    mount(HelpQuestion, {
        props: { id: 'iso-download', title: 'How do I download an ISO?' },
        slots: { default: '<p>Answer</p>' },
        global: { mocks: { $t: (key: string) => key } },
    });

describe('HelpQuestion', () => {
    afterEach(() => (window.location.hash = ''));

    it('shows the question closed', () => {
        const wrapper = mountQuestion();

        expect(wrapper.find('summary').text()).toBe(
            'How do I download an ISO?',
        );
        expect(wrapper.find('details').attributes('open')).toBeUndefined();
    });

    it('opens when the address points to it', async () => {
        window.location.hash = '#iso-download';

        const wrapper = mountQuestion();
        await nextTick();

        expect(wrapper.find('details').attributes('id')).toBe('iso-download');
        expect(wrapper.find('details').attributes('open')).toBeDefined();
    });
});
