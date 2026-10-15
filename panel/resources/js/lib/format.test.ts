import { describe, expect, it } from 'vite-plus/test';
import {
    formatBytes,
    formatMib,
    formatTraffic,
    percentage,
} from '@/lib/format';

describe('format', () => {
    it('formats sizes in binary units', () => {
        expect(formatBytes(0)).toBe('0 B');
        expect(formatBytes(1536)).toBe('1.5 KiB');
        expect(formatBytes(137438953472)).toBe('128 GiB');
        expect(formatBytes(null)).toBe('—');
        expect(formatMib(4096)).toBe('4 GiB');
    });

    it('formats traffic in decimal units with unlimited as ∞', () => {
        expect(formatTraffic(2 * 1000 ** 4)).toBe('2 TB');
        expect(formatTraffic(null)).toBe('∞');
    });

    it('computes percentages', () => {
        expect(percentage(25, 100)).toBe(25);
        expect(percentage(0, 100)).toBe(0);
        expect(percentage(null, 100)).toBeNull();
        expect(percentage(10, null)).toBeNull();
    });
});
