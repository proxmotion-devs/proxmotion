import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import { defineComponent, h } from 'vue';
import { installI18n } from '@/lib/i18n';

describe('installI18n', () => {
    it('translates the very first render, so hydration matches the server', () => {
        const Probe = defineComponent({
            render() {
                return h('span', this.$t('Servers'));
            },
        });

        const wrapper = mount(Probe, {
            global: { plugins: [(app) => installI18n(app, 'tr')] },
        });

        // Checked synchronously: no awaited tick before the first render.
        expect(wrapper.text()).toBe('Sunucular');
    });
});
