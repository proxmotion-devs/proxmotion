<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A queued or finished operation. Clients poll it until status is
 * "succeeded" or "failed".
 *
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'server' => [
                'id' => $this->server->uuid,
                'external_id' => $this->server->external_id,
                // Also the name generated for a server ordered without one (docs/adr/0031).
                'hostname' => $this->server->hostname,
                'status' => $this->server->status->value,
            ],
            'steps' => array_map(fn (array $step) => [
                'key' => $step['key'],
                'status' => $step['status'],
                'message' => $step['message'],
            ], $this->steps),
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
