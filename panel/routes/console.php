<?php

use App\Models\AppLog;
use App\Models\GuestScriptLog;
use App\Models\IdempotencyKey;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Schedule;

// Keep nodes, storages, bridges and templates in sync with Proxmox.
Schedule::command('infra:sync')->everyFiveMinutes()->withoutOverlapping();

// Drop stored answers of the billing API's idempotent requests after a day (docs/adr/0008).
Schedule::command('model:prune', ['--model' => [IdempotencyKey::class]])->hourly();

// Drop webhook deliveries after 30 days (docs/adr/0013).
Schedule::command('model:prune', ['--model' => [WebhookDelivery::class]])->daily();

// Drop the logs of app scripts and guest scripts after 90 days (docs/adr/0021, 0023).
Schedule::command('model:prune', ['--model' => [AppLog::class, GuestScriptLog::class]])->daily();

// Follow app installers running inside servers (docs/adr/0015).
Schedule::command('apps:poll')->everyMinute()->withoutOverlapping();

// Live figures of the servers administrators are watching; returns at once when nobody is (docs/adr/0036).
// The lock expires after a minute: a crashed run must not stop them for a day (the default).
Schedule::command('servers:stats')->everyTenSeconds()->withoutOverlapping(1);
