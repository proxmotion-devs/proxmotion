import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import LanguageSwitcher from '@/components/LanguageSwitcher.vue';

const patch = vi.fn();
const page = { props: { locale: 'en', availableLocales: ['en', 'tr'] } };

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    router: { patch: (...args: unknown[]) => patch(...args) },
}));

const mountSwitcher = () =>
    mount(LanguageSwitcher, {
        global: { mocks: { $t: (key: string) => key } },
    });

describe('LanguageSwitcher', () => {
    beforeEach(() => {
        patch.mockClear();
        page.props.locale = 'en';
    });

    it('shows every available language in its own language', () => {
        const buttons = mountSwitcher().findAll('button');

        expect(buttons.map((button) => button.text())).toEqual([
            'English',
            'Türkçe',
        ]);
        expect(buttons[0].attributes('aria-pressed')).toBe('true');
    });

    it('asks the server to switch the language', async () => {
        await mountSwitcher().findAll('button')[1].trigger('click');

        expect(patch).toHaveBeenCalledOnce();
        expect(patch.mock.calls[0][0]).toBe('/locale');
        expect(patch.mock.calls[0][1]).toEqual({ locale: 'tr' });
    });

    it('does nothing when the current language is clicked', async () => {
        await mountSwitcher().findAll('button')[0].trigger('click');

        expect(patch).not.toHaveBeenCalled();
    });
});
