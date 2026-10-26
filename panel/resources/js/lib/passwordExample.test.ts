import { describe, expect, it } from 'vite-plus/test';
import { passwordExample } from '@/lib/passwordExample';

describe('passwordExample', () => {
    it('has the chosen length around the prefix and suffix', () => {
        expect(passwordExample(16, 'lower', '', '.example!')).toMatch(
            /^[a-z0-9]{16}\.example!$/,
        );
        expect(passwordExample(12, 'mixed', 'ACME-', '')).toMatch(
            /^ACME-[A-Za-z0-9]{12}$/,
        );
    });

    it('holds every kind of its set', () => {
        for (let attempt = 0; attempt < 50; attempt++) {
            const example = passwordExample(12, 'mixed', '', '') ?? '';

            expect(example).toMatch(/[a-z]/);
            expect(example).toMatch(/[A-Z]/);
            expect(example).toMatch(/[0-9]/);
        }
    });

    it('uses symbols only with the symbols set', () => {
        expect(passwordExample(24, 'lower', '', '')).toMatch(/^[a-z0-9]+$/);
        expect(passwordExample(24, 'mixed', '', '')).toMatch(/^[A-Za-z0-9]+$/);
        expect(passwordExample(24, 'symbols', '', '')).toMatch(
            /^[A-Za-z0-9!@#%+=.\-_]+$/,
        );
    });

    it('is null while the length is out of range', () => {
        expect(passwordExample(11, 'mixed', '', '')).toBeNull();
        expect(passwordExample(65, 'mixed', '', '')).toBeNull();
        expect(passwordExample(Number.NaN, 'mixed', '', '')).toBeNull();
    });
});
