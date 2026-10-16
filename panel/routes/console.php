<?php

use App\Models\IdempotencyKey;
use Illuminate\Support\Facades\Schedule;

// Keep nodes, storages, bridges and templates in sync with Proxmox.
Schedule::command('infra:sync')->everyFiveMinutes()->withoutOverlapping();

// Drop stored answers of the billing API's idempotent requests after a day (docs/adr/0008).
Schedule::command('model:prune', ['--model' => [IdempotencyKey::class]])->hourly();
