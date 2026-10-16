<?php

use Illuminate\Support\Facades\Route;

// There is no client panel (docs/adr/0007): customers use their billing system.
Route::redirect('/', '/admin')->name('home');

Route::middleware(['auth', 'verified', '2fa'])
    ->prefix('admin')
    ->name('admin.')
    ->group(__DIR__.'/admin.php');

require __DIR__.'/settings.php';
