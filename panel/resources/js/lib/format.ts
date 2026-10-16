const BINARY_UNITS = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
const DECIMAL_UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

function scale(value: number, base: number, units: string[]): string {
    let unit = 0;

    while (Math.abs(value) >= base && unit < units.length - 1) {
        value /= base;
        unit++;
    }

    const rounded = Number.isInteger(value) ? value : Number(value.toFixed(1));

    return `${rounded} ${units[unit]}`;
}

/** Storage and memory sizes (binary units). */
export function formatBytes(bytes: number | null | undefined): string {
    return bytes === null || bytes === undefined
        ? '—'
        : scale(bytes, 1024, BINARY_UNITS);
}

/** Memory or disk sizes stored in MiB. */
export function formatMib(mib: number): string {
    return formatBytes(mib * 1024 ** 2);
}

/** Traffic volumes (decimal units, as billed by providers). */
export function formatTraffic(bytes: number | null | undefined): string {
    return bytes === null || bytes === undefined
        ? '∞'
        : scale(bytes, 1000, DECIMAL_UNITS);
}

/** Share of `used` in `total` as a whole percentage, or null when unknown. */
export function percentage(
    used: number | null | undefined,
    total: number | null | undefined,
): number | null {
    if (!used || !total) {
        return used === 0 && total ? 0 : null;
    }

    return Math.round((used / total) * 100);
}

/**
 * Localised date and time, e.g. "15 Oct 2026, 21:03", in the browser's time
 * zone unless `timeZone` is given. Components use `useDateTime()` instead.
 */
export function formatDateTime(
    iso: string | null | undefined,
    locale: string,
    timeZone?: string,
): string {
    if (!iso) {
        return '—';
    }

    return new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone,
    }).format(new Date(iso));
}
