import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import OsPicker from '@/components/apps/OsPicker.vue';
import type { Requires } from '@/lib/osPicker';
import type { Distribution } from '@/lib/osTemplates';

const templates = [
    { distribution: 'debian', version: '12' },
    { distribution: 'debian', version: '13' },
    { distribution: 'ubuntu', version: '24.04' },
];

const catalog: Distribution[] = [
    {
        id: 'debian',
        name: 'Debian',
        family: 'debian',
        base: null,
        same_version: false,
        releases: [],
    },
    {
        id: 'ubuntu',
        name: 'Ubuntu',
        family: 'debian',
        base: null,
        same_version: false,
        releases: [],
    },
];

const mountPicker = (requires: Requires) =>
    mount(OsPicker, {
        props: {
            modelValue: requires,
            'onUpdate:modelValue': () => {},
            templates,
            catalog,
        },
        global: { mocks: { $t: (key: string) => key } },
    });

describe('OsPicker', () => {
    it('shows the ticked versions of each distribution', () => {
        const wrapper = mountPicker({
            platform: 'linux',
            os: { families: ['debian'] },
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
        expect(wrapper.text()).toContain('Debian');
        expect(wrapper.find('[data-test="any-os"]').exists()).toBe(false);
    });

    it('writes distributions when a version is clicked', async () => {
        const wrapper = mountPicker({
            platform: 'linux',
            os: { distributions: ['ubuntu'] },
        });

        await wrapper.get('[data-test="os-debian-12"]').trigger('click');

        const [[requires]] = wrapper.emitted('update:modelValue') as [
            [Requires],
        ];
        expect(requires.os).toEqual({ distributions: ['debian', 'ubuntu'] });
        expect(requires.min_versions).toEqual({ debian: '12' });
    });

    it('warns that nothing ticked means every Linux template', () => {
        const wrapper = mountPicker({ platform: 'linux' });

        expect(wrapper.find('[data-test="any-os"]').exists()).toBe(true);
    });
});
