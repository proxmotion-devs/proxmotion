import { describe, expect, it } from 'vite-plus/test';
import {
    formatBytes,
    formatDateTime,
    formatMib,
    formatTraffic,
    formatUsageValue,
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

    it('formats quota and usage limit values in their units', () => {
        expect(formatUsageValue('bytes', 101 * 1000 ** 3)).toBe('101 GB');
        expect(formatUsageValue('percent', 95.5)).toBe('95.5%');
        expect(formatUsageValue('Mbps', 800)).toBe('800 Mbps');
    });

    it('computes percentages', () => {
        expect(percentage(25, 100)).toBe(25);
        expect(percentage(0, 100)).toBe(0);
        expect(percentage(null, 100)).toBeNull();
        expect(percentage(10, null)).toBeNull();
    });

    it('formats dates in the given time zone', () => {
        const iso = '2026-10-15T21:03:00Z';

        expect(formatDateTime(iso, 'en-GB', 'UTC')).toBe('15 Oct 2026, 21:03');
        expect(formatDateTime(iso, 'en-GB', 'Europe/Istanbul')).toBe(
            '16 Oct 2026, 00:03',
        );
        expect(formatDateTime(null, 'en-GB')).toBe('—');
    });
});
