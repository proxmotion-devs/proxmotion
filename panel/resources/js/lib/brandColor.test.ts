import { describe, expect, it } from 'vite-plus/test';
import {
    contrast,
    foreground,
    isBrandColor,
    lowContrastModes,
    resemblesStatus,
} from './brandColor';

describe('brandColor', () => {
    it('accepts #rrggbb only', () => {
        expect(isBrandColor('#1d4ed8')).toBe(true);
        expect(isBrandColor('#1D4ED8')).toBe(true);
        expect(isBrandColor('#fff')).toBe(false);
        expect(isBrandColor('red')).toBe(false);
        expect(isBrandColor('#1d4ed8;}')).toBe(false);
    });

    it('measures contrast like WCAG 2', () => {
        expect(contrast('#ffffff', '#000000')).toBeCloseTo(21, 5);
        expect(contrast('#777777', '#ffffff')).toBeCloseTo(4.48, 2);
    });

    it('picks the readable text colour', () => {
        expect(foreground('#1d4ed8')).toBe('#ffffff');
        expect(foreground('#facc15')).toBe('#000000');
    });

    it('names the modes a colour hardly stands out in', () => {
        expect(lowContrastModes('#2563eb')).toEqual([]);
        expect(lowContrastModes('#1d4ed8')).toEqual(['dark']);
        expect(lowContrastModes('#fef08a')).toEqual(['light']);
        expect(lowContrastModes('#111827')).toEqual(['dark']);
    });

    it('notices colours that look like a status tone', () => {
        expect(resemblesStatus('#f5c400')).toBe('warning');
        expect(resemblesStatus('#f59e0b')).toBe('warning');
        expect(resemblesStatus('#dc2626')).toBe('danger');
        expect(resemblesStatus('#2563eb')).toBeNull();
        expect(resemblesStatus('#16a34a')).toBeNull();
        // Greys and muted tones carry no status meaning.
        expect(resemblesStatus('#111827')).toBeNull();
        expect(resemblesStatus('#a8a29e')).toBeNull();
    });
});
