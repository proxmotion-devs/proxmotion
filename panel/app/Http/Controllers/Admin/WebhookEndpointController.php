<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Webhooks\Webhooks;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEvent;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BillingSystem;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webhook endpoints of a billing system and their deliveries
 * (docs/adr/0013), listed on the billing systems page. The secret is shown
 * once, after the endpoint is created or its secret replaced.
 */
class WebhookEndpointController extends Controller
{
    public function show(WebhookEndpoint $webhookEndpoint): Response
    {
        $webhookEndpoint->load('billingSystem:id,uuid,name');

        return Inertia::render('admin/webhooks/Show', [
            'endpoint' => self::present($webhookEndpoint),
            'billingSystem' => ['uuid' => $webhookEndpoint->billingSystem->uuid, 'name' => $webhookEndpoint->billingSystem->name],
            'deliveries' => $webhookEndpoint->deliveries()
                ->latest('id')
                ->paginate(50)
                ->through(fn (WebhookDelivery $delivery) => [
                    'uuid' => $delivery->uuid,
                    'event_id' => $delivery->event_id,
                    'event' => $delivery->event,
                    'status' => $delivery->status->value,
                    'attempts' => $delivery->attempts,
                    'response_status' => $delivery->response_status,
                    'error' => $delivery->error,
                    'payload' => $delivery->payload,
                    'next_attempt_at' => $delivery->next_attempt_at?->toIso8601String(),
                    'delivered_at' => $delivery->delivered_at?->toIso8601String(),
                    'created_at' => $delivery->created_at?->toIso8601String(),
                ]),
            'events' => self::eventOptions(),
            'newSecret' => session('new_webhook_secret'),
        ]);
    }

    public function store(Request $request, BillingSystem $billingSystem, #[CurrentUser] User $admin): RedirectResponse
    {
        $validated = $this->validated($request);
        $secret = WebhookEndpoint::newSecret();

        $endpoint = new WebhookEndpoint($validated);
        $endpoint->forceFill([
            'billing_system_id' => $billingSystem->id,
            'secret' => $secret,
            'created_by' => $admin->id,
        ])->save();

        AuditLog::record('webhook_endpoint.created', $admin, $endpoint, [
            'billing_system' => $billingSystem->name,
            'url' => $endpoint->url,
            'events' => $endpoint->events,
        ], $request->ip());

        $this->toast('success', 'Webhook endpoint created.');

        return to_route('admin.webhooks.show', $endpoint)->with('new_webhook_secret', $secret);
    }

    public function update(Request $request, WebhookEndpoint $webhookEndpoint, #[CurrentUser] User $admin): RedirectResponse
    {
        $webhookEndpoint->update($this->validated($request));

        AuditLog::record('webhook_endpoint.updated', $admin, $webhookEndpoint, [
            'url' => $webhookEndpoint->url,
            'events' => $webhookEndpoint->events,
            'active' => $webhookEndpoint->active,
        ], $request->ip());

        $this->toast('success', 'Webhook endpoint saved.');

        return back();
    }

    public function destroy(Request $request, WebhookEndpoint $webhookEndpoint, #[CurrentUser] User $admin): RedirectResponse
    {
        AuditLog::record('webhook_endpoint.deleted', $admin, $webhookEndpoint, ['url' => $webhookEndpoint->url], $request->ip());
        $webhookEndpoint->delete();

        $this->toast('success', 'Webhook endpoint deleted.');

        return to_route('admin.billing-systems.index');
    }

    /**
     * Replace the secret; the old one stops working at once.
     */
    public function secret(Request $request, WebhookEndpoint $webhookEndpoint, #[CurrentUser] User $admin): RedirectResponse
    {
        $secret = WebhookEndpoint::newSecret();
        $webhookEndpoint->forceFill(['secret' => $secret])->save();

        AuditLog::record('webhook_endpoint.secret_replaced', $admin, $webhookEndpoint, ['url' => $webhookEndpoint->url], $request->ip());

        $this->toast('success', 'Webhook secret replaced.');

        return to_route('admin.webhooks.show', $webhookEndpoint)->with('new_webhook_secret', $secret);
    }

    public function ping(WebhookEndpoint $webhookEndpoint, Webhooks $webhooks): RedirectResponse
    {
        $webhooks->ping($webhookEndpoint);

        $this->toast('success', 'Test event queued.');

        return back();
    }

    public function redeliver(Request $request, WebhookDelivery $webhookDelivery, Webhooks $webhooks, #[CurrentUser] User $admin): RedirectResponse
    {
        if ($webhookDelivery->status === WebhookDeliveryStatus::Pending) {
            $this->toast('error', 'This delivery is still being sent.');

            return back();
        }

        $webhooks->redeliver($webhookDelivery);
        AuditLog::record('webhook_delivery.redelivered', $admin, $webhookDelivery, ['event' => $webhookDelivery->event, 'event_id' => $webhookDelivery->event_id], $request->ip());

        $this->toast('success', 'Delivery queued again.');

        return back();
    }

    /**
     * @return array{url: string, events: list<string>, active: bool}
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            // http is allowed: the billing system may be on a private network. Only administrators enter URLs.
            'url' => ['required', 'string', 'max:2048', 'url:http,https'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'string', Rule::in(array_map(fn (WebhookEvent $event) => $event->value, WebhookEvent::subscribable()))],
            'active' => ['boolean'],
        ]);

        return [
            'url' => $validated['url'],
            'events' => array_values(array_unique($validated['events'])),
            'active' => (bool) ($validated['active'] ?? true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(WebhookEndpoint $endpoint): array
    {
        return [
            'uuid' => $endpoint->uuid,
            'url' => $endpoint->url,
            'events' => $endpoint->events,
            'active' => $endpoint->active,
            'failure_count' => $endpoint->failure_count,
            'last_delivery' => $endpoint->relationLoaded('lastDelivery') && $endpoint->lastDelivery !== null ? [
                'status' => $endpoint->lastDelivery->status->value,
                'created_at' => $endpoint->lastDelivery->created_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function eventOptions(): array
    {
        return array_map(fn (WebhookEvent $event) => ['value' => $event->value, 'label' => $event->label()], WebhookEvent::subscribable());
    }
}
