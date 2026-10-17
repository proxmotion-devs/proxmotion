<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AppInstallationStatus;
use App\Models\Concerns\HasPublicUuid;
use Carbon\CarbonInterface;
use Database\Factories\AppInstallationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An app installed (or being installed) on a server (docs/adr/0015). Inputs
 * and outputs are encrypted: they can hold passwords and VPN client files.
 * Only the App Marketplace code writes installations, so nothing is fillable.
 *
 * @property int $id
 * @property string $uuid
 * @property int $server_id
 * @property int $app_recipe_id
 * @property int $app_recipe_version_id the version installed (docs/adr/0016 §2)
 * @property AppInstallationStatus $status
 * @property array<string, string|int|bool> $inputs validated values
 * @property array<string, array{type: string, value: string, filename?: string|null}>|null $outputs by key; file contents base64-encoded
 * @property int $progress 0-100
 * @property string|null $step
 * @property string|null $error
 * @property int $attempts
 * @property int|null $task_id the install_app task of the latest attempt
 * @property int|null $user_id
 * @property int|null $api_token_id
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 * @property-read AppRecipe $recipe
 * @property-read AppRecipeVersion $version
 * @property-read Task|null $task
 */
class AppInstallation extends Model
{
    /** @use HasFactory<AppInstallationFactory> */
    use HasFactory, HasPublicUuid;

    protected $hidden = ['inputs', 'outputs'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AppInstallationStatus::class,
            'inputs' => 'encrypted:array',
            'outputs' => 'encrypted:array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<AppRecipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(AppRecipe::class, 'app_recipe_id');
    }

    /**
     * @return BelongsTo<AppRecipeVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(AppRecipeVersion::class, 'app_recipe_version_id');
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * The directory of this installation inside the server.
     */
    public function guestDirectory(): string
    {
        return '/var/lib/proxmotion/apps/'.$this->uuid;
    }

    /**
     * When the installer counts as hung: the recipe's timeout after the latest start.
     */
    public function deadline(): ?CarbonInterface
    {
        return $this->started_at?->copy()->addMinutes($this->version->recipe()->timeoutMinutes);
    }
}
