import { describe, expect, it } from 'vite-plus/test';
import { editable, osFromList } from '@/lib/recipes';
import type { RecipeDefinition } from '@/lib/recipes';

const recipe = (requires: Record<string, unknown>): RecipeDefinition =>
    ({
        name: 'Demo',
        category: 'web',
        description: { en: 'Demo' },
        requires: { platform: 'linux', ...requires },
    }) as unknown as RecipeDefinition;

describe('recipes', () => {
    it('reads the list form of older recipes as families and distributions', () => {
        expect(osFromList(['debian', 'almalinux', 'archlinux'])).toEqual({
            families: ['debian', 'arch'],
            distributions: ['almalinux'],
        });
        expect(osFromList([])).toBeUndefined();
    });

    it('converts older requirements when a recipe is opened', () => {
        const fromList = editable(
            recipe({
                os: ['debian', 'rocky'],
                min_versions: { archlinux: '2025', debian: '12' },
            }),
        );

        expect(fromList.requires.os).toEqual({
            families: ['debian'],
            distributions: ['rocky'],
        });
        expect(fromList.requires.min_versions).toEqual({
            arch: '2025',
            debian: '12',
        });

        const fromCapabilities = editable(
            recipe({ capabilities: ['systemd', 'apt|dnf'] }),
        );

        expect(fromCapabilities.requires.os).toEqual({
            families: ['debian', 'rhel'],
        });
        expect(fromCapabilities.requires.capabilities).toBeUndefined();
        expect(editable(recipe({})).requires.os).toBeUndefined();
    });

    it('keeps the object form as it is', () => {
        const os = { families: ['debian'], distributions: ['almalinux'] };

        expect(editable(recipe({ os })).requires.os).toEqual(os);
    });
});
