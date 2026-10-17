<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Webhooks\WebhookSignature;
use App\Enums\WebhookDeliveryStatus;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Sends one webhook delivery (docs/adr/0013). Only a 2xx answer counts as
 * delivered; otherwise the delivery is queued again with a growing delay
 * until MAX_ATTEMPTS, then marked failed. Manual sends (test events, sending
 * again from the admin panel) are tried once.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    /** Seconds to wait after each failed attempt (MAX_ATTEMPTS - 1 entries). */
    public const DELAYS = [60, 300, 1800, 3600, 7200, 21600, 43200];

    public const MAX_ATTEMPTS = 8;

    public const TIMEOUT_SECONDS = 10;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public WebhookDelivery $delivery, public bool $retry = true) {}

    public function handle(): void
    {
        $delivery = $this->delivery->fresh();

        if ($delivery === null || $delivery->status !== WebhookDeliveryStatus::Pending) {
            return;
        }

        /** @var WebhookEndpoint $endpoint */
        $endpoint = $delivery->endpoint;

        // Retries stop when an administrator disables the endpoint.
        if ($this->retry && ! $endpoint->active) {
            $delivery->forceFill([
                'status' => WebhookDeliveryStatus::Failed,
                'error' => 'The endpoint is disabled.',
                'next_attempt_at' => null,
            ])->save();

            return;
        }

        [$responseStatus, $error] = $this->send($endpoint, $delivery);
        $attempts = $delivery->attempts + 1;

        if ($error === null) {
            $delivery->forceFill([
                'status' => WebhookDeliveryStatus::Succeeded,
                'attempts' => $attempts,
                'response_status' => $responseStatus,
                'error' => null,
                'next_attempt_at' => null,
                'delivered_at' => now(),
            ])->save();
            $endpoint->forceFill(['failure_count' => 0])->save();

            return;
        }

        $retryAt = $this->retry && $attempts < self::MAX_ATTEMPTS
            ? now()->addSeconds(self::DELAYS[min($attempts, count(self::DELAYS)) - 1])
            : null;

        $delivery->forceFill([
            'status' => $retryAt !== null ? WebhookDeliveryStatus::Pending : WebhookDeliveryStatus::Failed,
            'attempts' => $attempts,
            'response_status' => $responseStatus,
            'error' => Str::limit($error, 990),
            'next_attempt_at' => $retryAt,
        ])->save();

        if ($retryAt !== null) {
            self::dispatch($delivery)->delay($retryAt);
        } else {
            $endpoint->increment('failure_count');
        }
    }

    /**
     * @return array{int|null, string|null} the HTTP status and an error, null when delivered
     */
    private function send(WebhookEndpoint $endpoint, WebhookDelivery $delivery): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->withUserAgent('Proxmotion-Webhooks/1.0')
                ->withHeaders([
                    'Proxmotion-Webhook-Id' => $delivery->event_id,
                    'Proxmotion-Webhook-Event' => $delivery->event,
                    WebhookSignature::HEADER => WebhookSignature::header($endpoint->secret, $delivery->payload, now()->getTimestamp()),
                ])
                ->withBody($delivery->payload, 'application/json')
                ->post($endpoint->url);
        } catch (ConnectionException $e) {
            return [null, 'Connection failed: '.$e->getMessage()];
        }

        return $response->successful()
            ? [$response->status(), null]
            : [$response->status(), 'HTTP '.$response->status()];
    }
}
