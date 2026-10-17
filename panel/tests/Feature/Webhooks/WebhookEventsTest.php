<?php

namespace Tests\Feature\Webhooks;

use App\Domain\Webhooks\Webhooks;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhook;
use App\Models\BillingSystem;
use App\Models\Server;
use App\Models\Task;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Provisioning\TaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

class WebhookEventsTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private BillingSystem $system;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        (new FakeProxmoxCluster)->addVm(100, 'web1.example.com')->install();
        $this->system = BillingSystem::factory()->create();
        $this->server = Server::factory()->for($this->node)->create([
            'vmid' => 100,
            'billing_system_id' => $this->system->id,
            'external_id' => '1001',
            'external_client_id' => '42',
        ]);

        // The task itself runs synchronously; deliveries are only recorded.
        Queue::fake([DeliverWebhook::class]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(WebhookDelivery $delivery): array
    {
        return json_decode($delivery->payload, true, flags: JSON_THROW_ON_ERROR);
    }

    private function finishedTask(TaskType $type, TaskStatus $status): Task
    {
        $task = new Task;
        $task->forceFill([
            'server_id' => $this->server->id,
            'type' => $type,
            'status' => $status,
            'steps' => [],
            'error' => $status === TaskStatus::Failed ? 'Clone failed.' : null,
        ])->save();

        return $task;
    }

    public function test_a_finished_task_sends_the_server_event_and_the_task_event()
    {
        $endpoint = WebhookEndpoint::factory()->for($this->system)->create();

        $task = app(TaskRunner::class)->start($this->server, TaskType::Suspend);

        $this->assertSame(TaskStatus::Succeeded, $task->refresh()->status);
        $deliveries = WebhookDelivery::query()->orderBy('id')->get();
        $this->assertSame(['server.suspended', 'task.succeeded'], $deliveries->pluck('event')->all());
        $this->assertNotSame($deliveries[0]->event_id, $deliveries[1]->event_id);
        $this->assertTrue($deliveries->every(fn (WebhookDelivery $delivery) => $delivery->status === WebhookDeliveryStatus::Pending && $delivery->webhook_endpoint_id === $endpoint->id));
        Queue::assertPushed(DeliverWebhook::class, 2);

        $payload = $this->payload($deliveries[0]);
        $this->assertSame($deliveries[0]->event_id, $payload['id']);
        $this->assertSame('server.suspended', $payload['event']);
        $this->assertSame([
            'id' => $this->server->uuid,
            'external_id' => '1001',
            'external_client_id' => '42',
            'hostname' => $this->server->hostname,
            'status' => ServerStatus::Suspended->value,
        ], $payload['data']['server']);
        $this->assertSame($task->uuid, $payload['data']['task']['id']);
        $this->assertSame('suspend', $payload['data']['task']['type']);
        $this->assertSame('succeeded', $payload['data']['task']['status']);
    }

    public function test_tasks_without_a_server_event_only_send_the_task_event()
    {
        WebhookEndpoint::factory()->for($this->system)->create();

        app(TaskRunner::class)->start($this->server, TaskType::Stop);

        $this->assertSame(['task.succeeded'], WebhookDelivery::query()->pluck('event')->all());
    }

    public function test_failed_creates_reinstalls_and_terminates_send_server_failed()
    {
        WebhookEndpoint::factory()->for($this->system)->create();
        $webhooks = app(Webhooks::class);

        $webhooks->taskFinished($this->finishedTask(TaskType::Create, TaskStatus::Failed));
        $this->assertSame(['server.failed', 'task.failed'], WebhookDelivery::query()->orderBy('id')->pluck('event')->all());
        $this->assertSame('Clone failed.', $this->payload(WebhookDelivery::query()->firstOrFail())['data']['task']['error']);

        WebhookDelivery::query()->delete();
        $webhooks->taskFinished($this->finishedTask(TaskType::Reboot, TaskStatus::Failed));
        $this->assertSame(['task.failed'], WebhookDelivery::query()->pluck('event')->all());
    }

    public function test_every_task_type_maps_to_the_documented_server_events()
    {
        $this->assertSame(WebhookEvent::ServerProvisioned, WebhookEvent::forTask(TaskType::Create, true));
        $this->assertSame(WebhookEvent::ServerUnsuspended, WebhookEvent::forTask(TaskType::Unsuspend, true));
        $this->assertSame(WebhookEvent::ServerReinstalled, WebhookEvent::forTask(TaskType::Reinstall, true));
        $this->assertSame(WebhookEvent::ServerTerminated, WebhookEvent::forTask(TaskType::Terminate, true));
        $this->assertSame(WebhookEvent::ServerFailed, WebhookEvent::forTask(TaskType::Terminate, false));
        $this->assertNull(WebhookEvent::forTask(TaskType::Suspend, false));
        $this->assertNull(WebhookEvent::forTask(TaskType::ChangeHostname, true));
        $this->assertNotContains(WebhookEvent::Ping, WebhookEvent::subscribable());
    }

    public function test_only_active_subscribed_endpoints_of_the_servers_billing_system_are_told()
    {
        $subscribed = WebhookEndpoint::factory()->for($this->system)->events([WebhookEvent::ServerSuspended])->create();
        WebhookEndpoint::factory()->for($this->system)->events([WebhookEvent::ServerProvisioned])->create();
        WebhookEndpoint::factory()->for($this->system)->create(['active' => false]);
        WebhookEndpoint::factory()->create();

        app(TaskRunner::class)->start($this->server, TaskType::Suspend);

        $delivery = WebhookDelivery::query()->sole();
        $this->assertSame($subscribed->id, $delivery->webhook_endpoint_id);
        $this->assertSame('server.suspended', $delivery->event);
    }

    public function test_one_event_has_the_same_id_at_every_endpoint()
    {
        WebhookEndpoint::factory()->count(2)->for($this->system)->events([WebhookEvent::TaskSucceeded])->create();

        app(TaskRunner::class)->start($this->server, TaskType::Stop);

        $deliveries = WebhookDelivery::query()->get();
        $this->assertCount(2, $deliveries);
        $this->assertCount(1, $deliveries->pluck('event_id')->unique());
    }

    public function test_servers_without_a_billing_system_send_nothing()
    {
        WebhookEndpoint::factory()->for($this->system)->create();
        $this->server->forceFill(['billing_system_id' => null, 'external_id' => null])->save();

        app(TaskRunner::class)->start($this->server, TaskType::Stop);

        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    public function test_payloads_never_carry_secrets()
    {
        WebhookEndpoint::factory()->for($this->system)->create();
        $task = $this->finishedTask(TaskType::ResetPassword, TaskStatus::Succeeded);
        $task->forceFill(['secret' => 'chosen-root-password'])->save();

        app(Webhooks::class)->taskFinished($task);

        $delivery = WebhookDelivery::query()->sole();
        $this->assertStringNotContainsString('chosen-root-password', $delivery->payload);
        $this->assertStringNotContainsString('secret', $delivery->payload);
    }
}
