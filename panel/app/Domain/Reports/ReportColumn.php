<?php

declare(strict_types=1);

namespace App\Domain\Reports;

/**
 * One column of a report table (docs/adr/0046). Cells hold raw values (MiB,
 * bytes, seconds, ISO 8601 times, enum values); the page formats them by
 * the column's type and the CSV export writes them as they are.
 *
 * Types: text, number, boolean, mib, traffic (bytes, decimal units),
 * percent (0–100), duration (seconds), datetime, server (hostname, linked
 * through the row's server_uuid), server_status, task_type, usage (a value
 * in the unit of the row's `unit` cell, docs/adr/0039).
 */
final readonly class ReportColumn
{
    public const array TYPES = [
        'text', 'number', 'boolean', 'mib', 'traffic', 'percent', 'duration',
        'datetime', 'server', 'server_status', 'task_type', 'usage',
    ];

    /**
     * @param  string  $key  also the CSV header
     * @param  string  $label  translated
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type = 'text',
    ) {}

    /**
     * @return array{key: string, label: string, type: string}
     */
    public function toPayload(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'type' => $this->type];
    }
}
