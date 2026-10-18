<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\AppRunStatus;
use App\Models\AppRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An uninstall, upgrade or action of an installed app (docs/adr/0016 §4). Password
 * inputs are never shown; outputs only for tokens with the apps:secrets
 * scope (withOutputs()), file contents base64-encoded.
 *
 * @mixin AppRun
 */
class AppRunResource extends JsonResource
{
    private bool $outputs = false;

    public function withOutputs(bool $outputs = true): static
    {
        $this->outputs = $outputs;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $declared = $this->declaredInputs();
        $inputs = [];

        foreach ($this->inputs as $key => $value) {
            foreach ($declared as $input) {
                if ($input->key === $key && ! $input->isSecret()) {
                    $inputs[$key] = $value;
                }
            }
        }

        return [
            'id' => $this->uuid,
            'installation_id' => $this->installation->uuid,
            'kind' => $this->kind->value,
            'action' => $this->action,
            // The version an upgrade moves the installation to.
            'target_version' => $this->targetVersion?->version,
            'status' => $this->status->value,
            'progress' => $this->progress,
            'step' => $this->step,
            'error' => $this->error,
            'inputs' => (object) $inputs,
            'outputs' => $this->when($this->outputs, fn () => $this->status === AppRunStatus::Succeeded
                ? AppInstallationResource::outputsOf($this->declaredOutputs(), $this->resource->outputs ?? [])
                : []),
            'task_id' => $this->task?->uuid,
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
