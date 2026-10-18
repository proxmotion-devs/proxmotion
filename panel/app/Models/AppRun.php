<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Apps\RecipeAction;
use App\Domain\Apps\RecipeInput;
use App\Domain\Apps\RecipeOutput;
use App\Enums\AppRunKind;
use App\Enums\AppRunStatus;
use App\Models\Concerns\HasPublicUuid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An uninstall, upgrade or action of an installed app (docs/adr/0016 §4). It
 * runs in its own directory inside the server (runs/<uuid> of the
 * installation) with the installation's recipe version; an upgrade with the
 * version it upgrades to (the installation moves to it when it succeeds). Inputs and outputs are encrypted.
 * Only the App Marketplace code writes runs, so nothing is fillable.
 *
 * @property int $id
 * @property string $uuid
 * @property int $app_installation_id
 * @property AppRunKind $kind
 * @property string|null $action key of the action
 * @property int|null $target_version_id the version an upgrade moves to
 * @property AppRunStatus $status
 * @property array<string, string|int|bool> $inputs validated values
 * @property array<string, array{type: string, value: string, filename?: string|null}>|null $outputs by key; file contents base64-encoded
 * @property int $progress 0-100
 * @property string|null $step
 * @property string|null $error
 * @property int|null $task_id
 * @property int|null $user_id
 * @property int|null $api_token_id
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AppInstallation $installation
 * @property-read AppRecipeVersion|null $targetVersion
 * @property-read Task|null $task
 */
class AppRun extends Model
{
    use HasPublicUuid;

    protected $hidden = ['inputs', 'outputs'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AppRunKind::class,
            'status' => AppRunStatus::class,
            'inputs' => 'encrypted:array',
            'outputs' => 'encrypted:array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AppInstallation, $this>
     */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(AppInstallation::class, 'app_installation_id');
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * What the script printed (docs/adr/0021).
     *
     * @return HasOne<AppLog, $this>
     */
    public function log(): HasOne
    {
        return $this->hasOne(AppLog::class);
    }

    /**
     * The version an upgrade moves the installation to (kind upgrade).
     *
     * @return BelongsTo<AppRecipeVersion, $this>
     */
    public function targetVersion(): BelongsTo
    {
        return $this->belongsTo(AppRecipeVersion::class, 'target_version_id');
    }

    /**
     * The recipe version whose scripts this run uses: the target of an
     * upgrade, the installation's own version otherwise.
     */
    public function runVersion(): AppRecipeVersion
    {
        return $this->kind === AppRunKind::Upgrade
            ? ($this->targetVersion ?? throw new LogicException("Upgrade {$this->uuid} has no target version."))
            : $this->installation->version;
    }

    /**
     * The action this run performs (kind action).
     */
    public function recipeAction(): ?RecipeAction
    {
        return $this->action === null ? null : $this->installation->version->recipe()->action($this->action);
    }

    /**
     * The script of this run, relative to the installation's directory.
     */
    public function script(): string
    {
        $script = match ($this->kind) {
            AppRunKind::Uninstall => $this->installation->version->recipe()->lifecycleScript('uninstall'),
            AppRunKind::Upgrade => $this->runVersion()->recipe()->lifecycleScript('upgrade'),
            AppRunKind::Action => $this->recipeAction()?->script,
        };

        return $script ?? throw new LogicException("Run {$this->uuid} has no script in its recipe version.");
    }

    /**
     * Outputs the script declares: an action's, or for an upgrade the
     * outputs of the version it upgrades to (they replace the installation's).
     *
     * @return list<RecipeOutput>
     */
    public function declaredOutputs(): array
    {
        return match ($this->kind) {
            AppRunKind::Uninstall => [],
            AppRunKind::Upgrade => $this->runVersion()->recipe()->outputs,
            AppRunKind::Action => $this->recipeAction()->outputs ?? [],
        };
    }

    /**
     * The inputs the run was started with: an action's own, or for an
     * upgrade the install inputs of the version it upgrades to.
     *
     * @return list<RecipeInput>
     */
    public function declaredInputs(): array
    {
        return match ($this->kind) {
            AppRunKind::Uninstall => [],
            AppRunKind::Upgrade => $this->runVersion()->recipe()->inputs,
            AppRunKind::Action => $this->recipeAction()->inputs ?? [],
        };
    }

    public function timeoutMinutes(): int
    {
        return match ($this->kind) {
            AppRunKind::Uninstall => $this->installation->version->recipe()->lifecycle['uninstall']['timeout_minutes'] ?? 5,
            AppRunKind::Upgrade => $this->runVersion()->recipe()->lifecycle['upgrade']['timeout_minutes'] ?? 10,
            AppRunKind::Action => $this->recipeAction()->timeoutMinutes ?? 5,
        };
    }

    public function guestDirectory(): string
    {
        return $this->installation->guestDirectory().'/runs/'.$this->uuid;
    }

    public function deadline(): ?CarbonInterface
    {
        return $this->started_at?->copy()->addMinutes($this->timeoutMinutes());
    }
}
