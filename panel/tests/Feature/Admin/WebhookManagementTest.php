<?php

namespace Tests\Feature\Admin;

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhook;
use App\Models\AuditLog;
use App\Models\BillingSystem;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

class WebhookManagementTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([DeliverWebhook::class]);
    }

    public function test_an_endpoint_is_created_and_its_secret_shown_once()
    {
        $system = BillingSystem::factory()->create(['name' => 'WHMCS']);

        $response = $this->post(route('admin.billing-systems.webhooks.store', $system), [
            'url' => 'https://billing.example.com/modules/servers/proxmotion/webhook.php',
            'events' => ['server.provisioned', 'server.failed', 'server.provisioned'],
        ]);

        $endpoint = WebhookEndpoint::query()->sole();
        $response->assertRedirect(route('admin.webhooks.show', $endpoint));
        $this->assertSame($system->id, $endpoint->billing_system_id);
        $this->assertSame(['server.provisioned', 'server.failed'], $endpoint->events);
        $this->assertTrue($endpoint->active);
        $this->assertSame($this->admin->id, $endpoint->created_by);

        $this->get(route('admin.webhooks.show', $endpoint))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/webhooks/Show')
                ->where('endpoint.url', $endpoint->url)
                ->missing('endpoint.secret')
                ->where('billingSystem.name', 'WHMCS')
                ->where('newSecret', $endpoint->secret)
                ->has('events', count(WebhookEvent::subscribable())));

        $this->get(route('admin.webhooks.show', $endpoint))->assertInertia(fn (Assert $page) => $page->where('newSecret', null));

        $log = AuditLog::query()->where('action', 'webhook_endpoint.created')->sole();
        $this->assertStringNotContainsString($endpoint->secret, (string) json_encode($log->properties));
    }

    public function test_the_endpoint_form_is_validated()
    {
        $system = BillingSystem::factory()->create();

        $this->post(route('admin.billing-systems.webhooks.store', $system), [
            'url' => 'ftp://billing.example.com/hook',
            'events' => ['ping', 'server.exploded'],
        ])->assertSessionHasErrors(['url', 'events.0', 'events.1']);

        $this->post(route('admin.billing-systems.webhooks.store', $system), ['url' => 'http://10.0.0.5/hook', 'events' => []])
            ->assertSessionHasErrors('events')
            ->assertSessionDoesntHaveErrors('url');

        $this->assertSame(0, WebhookEndpoint::query()->count());
    }

    public function test_billing_systems_list_their_endpoints_without_secrets()
    {
        $endpoint = WebhookEndpoint::factory()->create();
        $delivery = new WebhookDelivery;
        $delivery->forceFill([
            'webhook_endpoint_id' => $endpoint->id,
            'event_id' => '0f8fad5b-d9cb-469f-a165-70867728950e',
            'event' => 'ping',
            'payload' => '{}',
            'status' => WebhookDeliveryStatus::Failed,
        ])->save();

        $this->get(route('admin.billing-systems.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('billingSystems.0.webhooks.0.uuid', $endpoint->uuid)
                ->where('billingSystems.0.webhooks.0.last_delivery.status', 'failed')
                ->missing('billingSystems.0.webhooks.0.secret')
                ->has('webhookEvents'));
    }

    public function test_an_endpoint_is_updated_disabled_and_deleted()
    {
        $endpoint = WebhookEndpoint::factory()->create();

        $this->put(route('admin.webhooks.update', $endpoint), [
            'url' => 'https://billing.example.com/new',
            'events' => ['task.failed'],
            'active' => false,
        ])->assertSessionHasNoErrors();

        $endpoint->refresh();
        $this->assertSame('https://billing.example.com/new', $endpoint->url);
        $this->assertSame(['task.failed'], $endpoint->events);
        $this->assertFalse($endpoint->active);

        $this->delete(route('admin.webhooks.destroy', $endpoint))->assertRedirect(route('admin.billing-systems.index'));
        $this->assertModelMissing($endpoint);
        $this->assertSame(['webhook_endpoint.updated', 'webhook_endpoint.deleted'], AuditLog::query()->orderBy('id')->pluck('action')->all());
    }

    public function test_the_secret_is_replaced()
    {
        $endpoint = WebhookEndpoint::factory()->create();
        $old = $endpoint->secret;

        $this->post(route('admin.webhooks.secret', $endpoint))->assertRedirect(route('admin.webhooks.show', $endpoint));

        $new = $endpoint->refresh()->secret;
        $this->assertNotSame($old, $new);
        $this->get(route('admin.webhooks.show', $endpoint))->assertInertia(fn (Assert $page) => $page->where('newSecret', $new));
        $this->assertSame(1, AuditLog::query()->where('action', 'webhook_endpoint.secret_replaced')->count());
    }

    public function test_a_test_event_is_queued_and_finished_deliveries_can_be_sent_again()
    {
        $endpoint = WebhookEndpoint::factory()->create();

        $this->post(route('admin.webhooks.ping', $endpoint))->assertSessionHasNoErrors();

        $delivery = WebhookDelivery::query()->sole();
        $this->assertSame('ping', $delivery->event);
        Queue::assertPushed(DeliverWebhook::class, 1);

        // Still pending: not sent twice.
        $this->post(route('admin.webhook-deliveries.redeliver', $delivery));
        Queue::assertPushed(DeliverWebhook::class, 1);

        $delivery->forceFill(['status' => WebhookDeliveryStatus::Failed])->save();
        $this->post(route('admin.webhook-deliveries.redeliver', $delivery));
        Queue::assertPushed(DeliverWebhook::class, 2);
        $this->assertSame(WebhookDeliveryStatus::Pending, $delivery->refresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'webhook_delivery.redelivered')->count());

        $this->get(route('admin.webhooks.show', $endpoint))
            ->assertInertia(fn (Assert $page) => $page->where('deliveries.data.0.uuid', $delivery->uuid)->where('deliveries.data.0.event', 'ping'));
    }
}
