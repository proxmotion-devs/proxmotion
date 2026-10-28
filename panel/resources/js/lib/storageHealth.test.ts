import { describe, expect, it, vi } from 'vite-plus/test';
import { lifeUsed, problemText } from '@/lib/storageHealth';

vi.mock('laravel-vue-i18n', () => ({
    trans: (key: string, replace: Record<string, string>) =>
        Object.entries(replace).reduce(
            (text, [name, value]) => text.replace(`:${name}`, value),
            key,
        ),
}));

describe('storageHealth', () => {
    it('fills in the placeholders of a problem', () => {
        expect(
            problemText({
                severity: 'warning',
                message: ':percent% of its space is used.',
                replace: { percent: 87 },
            }),
        ).toBe('87% of its space is used.');
    });

    it('turns the life left into the life used', () => {
        expect(lifeUsed(97)).toBe(3);
        expect(lifeUsed(null)).toBeNull();
    });
});
