<?php

namespace Database\Factories;

use App\Enums\WebhookEvent;
use App\Models\BillingSystem;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEndpoint>
 */
class WebhookEndpointFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'billing_system_id' => BillingSystem::factory(),
            'url' => 'https://billing.example.com/webhooks/proxmotion',
            'secret' => WebhookEndpoint::newSecret(),
            'events' => array_map(fn (WebhookEvent $event) => $event->value, WebhookEvent::subscribable()),
            'active' => true,
        ];
    }

    /**
     * @param  list<WebhookEvent>  $events
     */
    public function events(array $events): static
    {
        return $this->state(fn () => ['events' => array_map(fn (WebhookEvent $event) => $event->value, $events)]);
    }
}
