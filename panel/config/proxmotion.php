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

];
