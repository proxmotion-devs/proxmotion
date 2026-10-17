<?php

use App\Models\IdempotencyKey;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Schedule;

// Keep nodes, storages, bridges and templates in sync with Proxmox.
Schedule::command('infra:sync')->everyFiveMinutes()->withoutOverlapping();

// Drop stored answers of the billing API's idempotent requests after a day (docs/adr/0008).
Schedule::command('model:prune', ['--model' => [IdempotencyKey::class]])->hourly();

// Drop webhook deliveries after 30 days (docs/adr/0013).
Schedule::command('model:prune', ['--model' => [WebhookDelivery::class]])->daily();
