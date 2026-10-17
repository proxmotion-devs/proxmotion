<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Apps\RecipeOutput;
use App\Enums\AppInstallationStatus;
use App\Models\AppInstallation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An app installed or being installed on a server (docs/adr/0015). Password
 * inputs are never shown; outputs only for tokens with the apps:secrets
 * scope (withOutputs()), file contents base64-encoded.
 *
 * @mixin AppInstallation
 */
class AppInstallationResource extends JsonResource
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
        $recipe = $this->version->recipe();
        $inputs = [];

        foreach ($this->inputs as $key => $value) {
            if ($recipe->input($key)?->isSecret() === false) {
                $inputs[$key] = $value;
            }
        }

        return [
            'id' => $this->uuid,
            'app' => [
                'id' => $this->recipe->slug,
                'name' => $this->recipe->name,
                'version' => $this->version->version,
            ],
            'server' => [
                'id' => $this->server->uuid,
                'external_id' => $this->server->external_id,
            ],
            'status' => $this->status->value,
            'progress' => $this->progress,
            'step' => $this->step,
            'error' => $this->error,
            'inputs' => (object) $inputs,
            'outputs' => $this->when($this->outputs, fn () => $this->status === AppInstallationStatus::Installed ? $this->outputList($recipe->outputs) : []),
            'task_id' => $this->task?->uuid,
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<RecipeOutput>  $declared
     * @return list<array<string, mixed>>
     */
    private function outputList(array $declared): array
    {
        $stored = $this->resource->outputs ?? [];
        $list = [];

        foreach ($declared as $output) {
            $value = $stored[$output->key] ?? null;

            if ($value === null) {
                continue;
            }

            $list[] = [
                'key' => $output->key,
                'type' => $output->type,
                'label' => $output->label,
                'secret' => $output->secret,
                'value' => $output->isFile() ? null : $value['value'],
                'filename' => $output->isFile() ? $output->filename : null,
                'content_base64' => $output->isFile() ? $value['value'] : null,
            ];
        }

        return $list;
    }
}
