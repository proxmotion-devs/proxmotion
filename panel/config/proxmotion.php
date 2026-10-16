<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Require two-factor authentication for administrators
    |--------------------------------------------------------------------------
    |
    | When enabled, administrators must confirm TOTP two-factor authentication
    | before they can open any admin page. Only disable this for local
    | development.
    |
    */

    'require_admin_two_factor' => (bool) env('PROXMOTION_REQUIRE_ADMIN_2FA', true),

    /*
    |--------------------------------------------------------------------------
    | Browser console
    |--------------------------------------------------------------------------
    |
    | The console WebSocket is relayed by the console proxy service
    | (deploy/console-proxy, see docs/adr/0002). The proxy authenticates to
    | the panel with the shared secret. The WebSocket URL defaults to
    | /console-ws on the panel's own host.
    |
    */

    'console' => [
        'proxy_secret' => env('CONSOLE_PROXY_SECRET'),
        'websocket_url' => env('CONSOLE_WEBSOCKET_URL'),
        'ticket_seconds' => 15,
        'max_minutes' => (int) env('CONSOLE_MAX_MINUTES', 60),
        'idle_minutes' => (int) env('CONSOLE_IDLE_MINUTES', 15),
    ],

];
