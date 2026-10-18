<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AppInstallationStatus;
use App\Enums\AppRunStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one install attempt, uninstall, upgrade or action printed inside the
 * server (run.log of its directory, docs/adr/0021). The panel copies the
 * file while the script runs, masks secret values and keeps at most
 * MAX_BYTES: past that, the start (head_bytes) and the newest output are
 * kept and the middle is left out. Encrypted, shown to administrators only,
 * pruned after KEEP_DAYS. Only the App Marketplace code writes logs, so
 * nothing is fillable.
 *
 * @property int $id
 * @property string $uuid
 * @property int $app_installation_id
 * @property int|null $app_run_id null for an install attempt
 * @property int|null $attempt the install attempt (AppInstallation::$attempts)
 * @property string|null $content
 * @property int $offset bytes of run.log read so far
 * @property int $length bytes ever appended to content
 * @property int $head_bytes
 * @property int $omitted_bytes
 * @property Carbon|null $read_at
 * @property Carbon|null $completed_at the script ended and the whole file was read (or could no longer be)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AppInstallation $installation
 * @property-read AppRun|null $run
 */
class AppLog extends Model
{
    use HasPublicUuid, MassPrunable;

    public const int KEEP_DAYS = 90;

    /** The most kept of one log. */
    public const int MAX_BYTES = 1048576;

    /** How much of the start is kept when the middle is left out. */
    public const int HEAD_BYTES = 131072;

    protected $hidden = ['content'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content' => 'encrypted',
            'read_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /**
     * @return BelongsTo<AppInstallation, $this>
     */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(AppInstallation::class, 'app_installation_id');
    }

    /**
     * @return BelongsTo<AppRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AppRun::class, 'app_run_id');
    }

    /**
     * The file inside the server that run.sh writes the script's output to.
     */
    public function guestFile(): string
    {
        $directory = $this->app_run_id === null ? $this->installation->guestDirectory() : ($this->run?->guestDirectory() ?? '');

        return $directory.'/run.log';
    }

    /**
     * Whether the script is still running, so the file may grow.
     */
    public function isLive(): bool
    {
        if ($this->completed_at !== null) {
            return false;
        }

        if ($this->run !== null) {
            return $this->run->status === AppRunStatus::Running;
        }

        return $this->installation->status === AppInstallationStatus::Installing && $this->installation->attempts === $this->attempt;
    }
}
