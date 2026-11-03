import { trans } from 'laravel-vue-i18n';

/** How often the panel backs a server up by itself (docs/adr/0051 §6). */
export type BackupSchedule = 'none' | 'daily' | 'weekly';

/** English keys, translated where they are shown. */
export const BACKUP_SCHEDULE_LABELS: Record<BackupSchedule, string> = {
    none: 'No scheduled backups',
    daily: 'Every day',
    weekly: 'Every week',
};

export const backupScheduleOptions = (): {
    value: BackupSchedule;
    label: string;
}[] =>
    (Object.keys(BACKUP_SCHEDULE_LABELS) as BackupSchedule[]).map((value) => ({
        value,
        label: trans(BACKUP_SCHEDULE_LABELS[value]),
    }));
