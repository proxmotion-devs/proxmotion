<?php

namespace Tests\Feature\Webhooks;

use App\Domain\Webhooks\Webhooks;
use App\Enums\WebhookDeliveryStatus;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private WebhookEndpoint $endpoint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->endpoint = WebhookEndpoint::factory()->create(['url' => 'https://billing.example.com/hook']);
        Queue::fake([DeliverWebhook::class]);
    }

    private function delivery(array $attributes = []): WebhookDelivery
    {
        $delivery = new WebhookDelivery;
        $delivery->forceFill([
            'webhook_endpoint_id' => $this->endpoint->id,
            'event_id' => '0f8fad5b-d9cb-469f-a165-70867728950e',
            'event' => 'server.provisioned',
            'payload' => '{"id":"0f8fad5b-d9cb-469f-a165-70867728950e","event":"server.provisioned","data":{}}',
            'status' => WebhookDeliveryStatus::Pending,
            ...$attributes,
        ])->save();

        return $delivery;
    }

    public function test_a_delivery_is_signed_and_marked_delivered_on_a_2xx_answer()
    {
        $this->travelTo(now()->setTimestamp(1_800_000_000));
        Http::preventStrayRequests()->fake(['billing.example.com/*' => Http::response('', 204)]);
        $this->endpoint->forceFill(['failure_count' => 3])->save();
        $delivery = $this->delivery();

        (new DeliverWebhook($delivery))->handle();

        Http::assertSent(function (Request $request) use ($delivery) {
            $expected = 't=1800000000,v1='.hash_hmac('sha256', '1800000000.'.$delivery->payload, $this->endpoint->secret);

            return $request->url() === 'https://billing.example.com/hook'
                && $request->method() === 'POST'
                && $request->body() === $delivery->payload
                && $request->hasHeader('Content-Type', 'application/json')
                && $request->hasHeader('Proxmotion-Webhook-Id', $delivery->event_id)
                && $request->hasHeader('Proxmotion-Webhook-Event', 'server.provisioned')
                && $request->hasHeader('Proxmotion-Signature', $expected);
        });

        $delivery->refresh();
        $this->assertSame(WebhookDeliveryStatus::Succeeded, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(204, $delivery->response_status);
        $this->assertNotNull($delivery->delivered_at);
        $this->assertSame(0, $this->endpoint->refresh()->failure_count);
        Queue::assertNothingPushed();
    }

    public function test_a_failed_attempt_is_retried_later_with_a_growing_delay()
    {
        Http::preventStrayRequests()->fake(['billing.example.com/*' => Http::response('Oops', 500)]);
        $delivery = $this->delivery();

        (new DeliverWebhook($delivery))->handle();

        $delivery->refresh();
        $this->assertSame(WebhookDeliveryStatus::Pending, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(500, $delivery->response_status);
        $this->assertSame('HTTP 500', $delivery->error);
        $this->assertTrue($delivery->next_attempt_at?->between(now()->addSeconds(59), now()->addSeconds(61)));
        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->delivery->is($delivery) && $job->retry && $job->delay !== null);

        // The third attempt waits 30 minutes.
        $delivery->forceFill(['attempts' => 2])->save();
        (new DeliverWebhook($delivery))->handle();
        $this->assertTrue($delivery->refresh()->next_attempt_at?->between(now()->addSeconds(1799), now()->addSeconds(1801)));
    }

    public function test_a_delivery_fails_after_the_last_attempt()
    {
        Http::preventStrayRequests()->fake(['billing.example.com/*' => Http::response('', 404)]);
        $delivery = $this->delivery(['attempts' => DeliverWebhook::MAX_ATTEMPTS - 1]);

        (new DeliverWebhook($delivery))->handle();

        $delivery->refresh();
        $this->assertSame(WebhookDeliveryStatus::Failed, $delivery->status);
        $this->assertSame(DeliverWebhook::MAX_ATTEMPTS, $delivery->attempts);
        $this->assertNull($delivery->next_attempt_at);
        $this->assertSame(1, $this->endpoint->refresh()->failure_count);
        Queue::assertNothingPushed();
        $this->assertCount(DeliverWebhook::MAX_ATTEMPTS - 1, DeliverWebhook::DELAYS);
    }

    public function test_redirects_are_not_followed()
    {
        Http::preventStrayRequests()->fake([
            'billing.example.com/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin']),
        ]);
        $delivery = $this->delivery();

        (new DeliverWebhook($delivery))->handle();

        Http::assertSentCount(1);
        $this->assertSame('HTTP 302', $delivery->refresh()->error);
    }

    public function test_connection_failures_are_recorded()
    {
        Http::preventStrayRequests()->fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));
        $delivery = $this->delivery();

        (new DeliverWebhook($delivery))->handle();

        $delivery->refresh();
        $this->assertSame(WebhookDeliveryStatus::Pending, $delivery->status);
        $this->assertNull($delivery->response_status);
        $this->assertSame('Connection failed: cURL error 7: Failed to connect', $delivery->error);
    }

    public function test_manual_sends_are_tried_once()
    {
        Http::preventStrayRequests()->fake(['billing.example.com/*' => Http::response('', 500)]);
        $delivery = $this->delivery();

        (new DeliverWebhook($delivery, retry: false))->handle();

        $this->assertSame(WebhookDeliveryStatus::Failed, $delivery->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_retries_stop_when_the_endpoint_is_disabled()
    {
        Http::preventStrayRequests()->fake();
        $this->endpoint->update(['active' => false]);
        $delivery = $this->delivery(['attempts' => 2]);

        (new DeliverWebhook($delivery))->handle();

        Http::assertNothingSent();
        $delivery->refresh();
        $this->assertSame(WebhookDeliveryStatus::Failed, $delivery->status);
        $this->assertSame('The endpoint is disabled.', $delivery->error);
    }

    public function test_finished_deliveries_are_not_sent_again_by_a_stale_job()
    {
        Http::preventStrayRequests()->fake();
        $delivery = $this->delivery(['status' => WebhookDeliveryStatus::Succeeded]);

        (new DeliverWebhook($delivery))->handle();

        Http::assertNothingSent();
    }

    public function test_a_test_event_and_a_redelivery_keep_their_body_and_event_id()
    {
        $ping = app(Webhooks::class)->ping($this->endpoint);

        $payload = json_decode($ping->payload, true);
        $this->assertSame('ping', $payload['event']);
        $this->assertSame($this->endpoint->billingSystem->uuid, $payload['data']['billing_system']['id']);
        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->delivery->is($ping) && ! $job->retry);

        $ping->forceFill(['status' => WebhookDeliveryStatus::Failed])->save();
        $body = $ping->payload;
        app(Webhooks::class)->redeliver($ping);

        $ping->refresh();
        $this->assertSame(WebhookDeliveryStatus::Pending, $ping->status);
        $this->assertSame($body, $ping->payload);
        $this->assertSame($payload['id'], $ping->event_id);
        Queue::assertPushed(DeliverWebhook::class, 2);
    }

    public function test_deliveries_are_pruned_after_30_days()
    {
        $old = $this->delivery();
        $old->forceFill(['created_at' => now()->subDays(31)])->save();
        $recent = $this->delivery();

        Artisan::call('model:prune', ['--model' => [WebhookDelivery::class]]);

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    public function test_the_secret_is_encrypted_and_hidden()
    {
        $this->assertStringStartsWith(WebhookEndpoint::SECRET_PREFIX, $this->endpoint->secret);
        $this->assertArrayNotHasKey('secret', $this->endpoint->toArray());
        $stored = (string) $this->endpoint->getConnection()->table('webhook_endpoints')->value('secret');
        $this->assertStringNotContainsString($this->endpoint->secret, $stored);
    }
}
