<?php

use App\Http\Controllers\Internal\ConsoleProxyController;
use Illuminate\Support\Facades\Route;

/*
| Internal routes for panel services (no session, no CSRF). Loaded from
| bootstrap/app.php with the `internal/` prefix.
*/

Route::post('console/redeem', [ConsoleProxyController::class, 'redeem'])->name('console.redeem');
Route::post('console/heartbeat', [ConsoleProxyController::class, 'heartbeat'])->name('console.heartbeat');
