<?php

use Illuminate\Support\Facades\Schedule;

// Keep nodes, storages, bridges and templates in sync with Proxmox.
Schedule::command('infra:sync')->everyFiveMinutes()->withoutOverlapping();
