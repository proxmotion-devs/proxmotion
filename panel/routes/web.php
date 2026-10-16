<?php

use App\Http\Controllers\CustomerConsoleController;
use Illuminate\Support\Facades\Route;

// There is no client panel (docs/adr/0007): customers use their billing system.
Route::redirect('/', '/admin')->name('home');

Route::middleware(['auth', 'verified', '2fa'])
    ->prefix('admin')
    ->name('admin.')
    ->group(__DIR__.'/admin.php');

require __DIR__.'/settings.php';

// The customer console, opened from the billing system through a signed single-use link (docs/adr/0008).
Route::get('console/{server}/link/{nonce}', [CustomerConsoleController::class, 'link'])
    ->middleware(['signed', 'throttle:20,1'])
    ->name('console.link');
Route::get('console/{server}', [CustomerConsoleController::class, 'show'])
    ->middleware('throttle:20,1')
    ->name('console.show');
