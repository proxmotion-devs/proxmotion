<?php

declare(strict_types=1);

namespace App\Domain\Webhooks;

use App\Enums\TaskStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEvent;
use App\Http\Resources\Api\V1\TaskResource;
use App\Jobs\DeliverWebhook;
use App\Models\BillingSystem;
use App\Models\Server;
use App\Models\Task;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Str;

/**
 * Turns finished tasks into webhook deliveries for the endpoints of the
 * server's billing system and queues them (docs/adr/0013). Payloads carry
 * the same shapes as the API and never secrets.
 */
final class Webhooks
{
    /**
     * Tell the billing system that a task of one of its servers finished:
     * task.succeeded / task.failed, plus the server event of the task type.
     */
    public function taskFinished(Task $task): void
    {
        $server = $task->server()->first();

        if ($server === null || $server->billing_system_id === null) {
            return;
        }

        $endpoints = WebhookEndpoint::query()
            ->where('billing_system_id', $server->billing_system_id)
            ->where('active', true)
            ->get();

        if ($endpoints->isEmpty()) {
            return;
        }

        $task->setRelation('server', $server);
        $succeeded = $task->status === TaskStatus::Succeeded;
        $data = [
            'server' => self::server($server),
            'task' => (new TaskResource($task))->resolve(),
        ];

        $events = array_filter([
            WebhookEvent::forTask($task->type, $succeeded),
            $succeeded ? WebhookEvent::TaskSucceeded : WebhookEvent::TaskFailed,
        ]);

        foreach ($events as $event) {
            $eventId = (string) Str::uuid();

            foreach ($endpoints as $endpoint) {
                if ($endpoint->wants($event)) {
                    $this->queue($endpoint, $eventId, $event, $data, retry: true);
                }
            }
        }
    }

    /**
     * A test event for one endpoint, sent once without retries.
     */
    public function ping(WebhookEndpoint $endpoint): WebhookDelivery
    {
        /** @var BillingSystem $system */
        $system = $endpoint->billingSystem;

        return $this->queue($endpoint, (string) Str::uuid(), WebhookEvent::Ping, [
            'billing_system' => ['id' => $system->uuid, 'name' => $system->name],
        ], retry: false);
    }

    /**
     * Send a finished delivery again, once, with the same event id and body.
     */
    public function redeliver(WebhookDelivery $delivery): void
    {
        $delivery->forceFill(['status' => WebhookDeliveryStatus::Pending, 'next_attempt_at' => null])->save();

        DeliverWebhook::dispatch($delivery, false)->afterCommit();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function queue(WebhookEndpoint $endpoint, string $eventId, WebhookEvent $event, array $data, bool $retry): WebhookDelivery
    {
        $delivery = new WebhookDelivery;
        $delivery->forceFill([
            'webhook_endpoint_id' => $endpoint->id,
            'event_id' => $eventId,
            'event' => $event->value,
            'payload' => json_encode([
                'id' => $eventId,
                'event' => $event->value,
                'created_at' => now()->toIso8601String(),
                'data' => $data,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'status' => WebhookDeliveryStatus::Pending,
        ])->save();

        // Inside a transaction, the receiver must not be told before the change is committed.
        DeliverWebhook::dispatch($delivery, $retry)->afterCommit();

        return $delivery;
    }

    /**
     * @return array<string, mixed>
     */
    private static function server(Server $server): array
    {
        return [
            'id' => $server->uuid,
            'external_id' => $server->external_id,
            'external_client_id' => $server->external_client_id,
            'hostname' => $server->hostname,
            'status' => $server->status->value,
        ];
    }
}
