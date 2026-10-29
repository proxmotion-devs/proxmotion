<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Models\Package;
use App\Models\Server;
use Illuminate\Foundation\Http\FormRequest;

/**
 * How many snapshots and backups a server may have (docs/adr/0051 §2); 0
 * turns the feature off for it. From the package, the admin forms of custom
 * servers, or the billing API's resources. Lowering them deletes nothing.
 */
final readonly class BackupSlots
{
    public const int MAX = 100;

    public function __construct(
        public int $snapshots = 0,
        public int $backups = 0,
    ) {}

    public static function of(Package|Server $model): self
    {
        return new self(snapshots: $model->snapshot_slots, backups: $model->backup_slots);
    }

    /**
     * Validation of the form fields, the same in every admin form; empty is 0.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'snapshot_slots' => ['nullable', 'integer', 'between:0,'.self::MAX],
            'backup_slots' => ['nullable', 'integer', 'between:0,'.self::MAX],
        ];
    }

    public static function fromRequest(FormRequest $request, string $prefix = ''): self
    {
        return new self(
            snapshots: $request->integer("{$prefix}snapshot_slots"),
            backups: $request->integer("{$prefix}backup_slots"),
        );
    }

    /**
     * @return array{snapshot_slots: int, backup_slots: int}
     */
    public function columns(): array
    {
        return [
            'snapshot_slots' => $this->snapshots,
            'backup_slots' => $this->backups,
        ];
    }
}
