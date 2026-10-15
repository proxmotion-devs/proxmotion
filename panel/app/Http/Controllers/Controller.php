<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

abstract class Controller
{
    /**
     * Show a toast after the next page load. Pass an English translation key.
     *
     * @param  'success'|'info'|'warning'|'error'  $type
     * @param  array<string, string>  $replace
     */
    protected function toast(string $type, string $message, array $replace = []): void
    {
        Inertia::flash('toast', ['type' => $type, 'message' => __($message, $replace)]);
    }
}
