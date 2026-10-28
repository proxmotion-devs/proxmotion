import { trans } from 'laravel-vue-i18n';
import type { HealthProblem, StorageHealth } from '@/types/admin';

/** Badge tone per health (docs/adr/0047). */
export const healthTone: Record<
    StorageHealth,
    'success' | 'warning' | 'danger' | 'neutral'
> = {
    ok: 'success',
    warning: 'warning',
    critical: 'danger',
    unknown: 'neutral',
};

/** Badge label per health (English keys, translated by StatusBadge). */
export const healthLabel: Record<StorageHealth, string> = {
    ok: 'Healthy',
    warning: 'Warning',
    critical: 'Critical',
    unknown: 'Unknown',
};

/** A problem in the reader's language. */
export function problemText(problem: HealthProblem): string {
    return trans(
        problem.message,
        Object.fromEntries(
            Object.entries(problem.replace).map(([key, value]) => [
                key,
                String(value),
            ]),
        ),
    );
}

/**
 * The share of a disk's rated life used, as Proxmox's GUI shows "Wearout":
 * the API's wearout is the life left.
 */
export function lifeUsed(wearout: number | null): number | null {
    return wearout === null ? null : 100 - wearout;
}
