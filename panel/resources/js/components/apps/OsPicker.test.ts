import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import OsPicker from '@/components/apps/OsPicker.vue';
import type { Requires } from '@/lib/osPicker';

const templates = [
    { family: 'debian', version: '12' },
    { family: 'debian', version: '13' },
    { family: 'ubuntu', version: '24.04' },
];

const groups = { debian: { families: ['debian', 'ubuntu'] } };

const mountPicker = (requires: Requires) =>
    mount(OsPicker, {
        props: {
            modelValue: requires,
            'onUpdate:modelValue': () => {},
            templates,
            groups,
        },
        global: { mocks: { $t: (key: string) => key } },
    });

describe('OsPicker', () => {
    it('shows the ticked versions of each family', () => {
        const wrapper = mountPicker({
            platform: 'linux',
            os: ['debian'],
            min_versions: { debian: '13', ubuntu: '24.04' },
        });

        expect(
            wrapper
                .get('[data-test="os-debian-13"]')
                .attributes('aria-pressed'),
        ).toBe('true');
        expect(
            wrapper
                .get('[data-test="os-debian-12"]')
                .attributes('aria-pressed'),
        ).toBe('false');
        expect(
            wrapper
                .get('[data-test="os-ubuntu-24.04"]')
                .attributes('aria-pressed'),
        ).toBe('true');
        expect(wrapper.find('[data-test="any-os"]').exists()).toBe(false);
    });

    it('writes plain families when a version is clicked', async () => {
        const wrapper = mountPicker({ platform: 'linux', os: ['ubuntu'] });

        await wrapper.get('[data-test="os-debian-12"]').trigger('click');

        const [[requires]] = wrapper.emitted('update:modelValue') as [
            [Requires],
        ];
        expect(requires.os).toEqual(['debian', 'ubuntu']);
        expect(requires.min_versions).toEqual({ debian: '12' });
    });

    it('warns that nothing ticked means every Linux template', () => {
        const wrapper = mountPicker({ platform: 'linux' });

        expect(wrapper.find('[data-test="any-os"]').exists()).toBe(true);
    });
});
