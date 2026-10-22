<?php

/*
| Everything here except require_admin_two_factor, console.proxy_secret,
| console.websocket_url and console.ticket_seconds is only a default: once
| the Panel settings page is saved, its values are used
| (App\Domain\Settings\PanelSettings, docs/adr/0038).
*/

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
    | Documentation
    |--------------------------------------------------------------------------
    |
    | Where the full guides (the repository's docs/ folder) can be read, e.g.
    | your fork or mirror. The Help page links to it when set.
    |
    */

    // `?: null`: an empty PROXMOTION_DOCS_URL= (as in .env.example) means unset.
    'docs_url' => env('PROXMOTION_DOCS_URL') ?: null,

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

    /*
    |--------------------------------------------------------------------------
    | Provisioning
    |--------------------------------------------------------------------------
    |
    | New servers get the first free VMID from min_vmid upwards (0: from
    | Proxmox's next free ID), e.g. to keep them apart from VMs managed by hand.
    |
    | Creating or reinstalling a server must finish within deadline_minutes
    | of its start, all its waits included (docs/adr/0032).
    |
    | After that, the server checks from inside that verify_dns_name resolves
    | (empty: DNS is not checked). example.com is reserved by IANA and
    | resolves everywhere.
    |
    */

    'provisioning' => [
        'min_vmid' => (int) env('PROXMOTION_MIN_VMID', 0),
        'deadline_minutes' => (int) env('PROXMOTION_PROVISIONING_DEADLINE_MINUTES', 120),
        'verify_dns_name' => (string) env('PROXMOTION_VERIFY_DNS_NAME', 'example.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Moving servers (docs/adr/0037)
    |--------------------------------------------------------------------------
    |
    | Moves to another cluster or location use Proxmox's remote migration,
    | which Proxmox still calls experimental: they are offered only with
    | cross_cluster on. A move must finish within deadline_minutes (copying
    | disks can take hours); bwlimit_kib limits its transfer (KiB/s, empty:
    | Proxmox's own limit).
    |
    */

    'moves' => [
        'cross_cluster' => (bool) env('PROXMOTION_CROSS_CLUSTER_MOVES', false),
        'deadline_minutes' => (int) env('PROXMOTION_MOVE_DEADLINE_MINUTES', 720),
        'bwlimit_kib' => env('PROXMOTION_MOVE_BWLIMIT_KIB') !== null && env('PROXMOTION_MOVE_BWLIMIT_KIB') !== '' ? (int) env('PROXMOTION_MOVE_BWLIMIT_KIB') : null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Guest isolation (docs/adr/0034)
    |--------------------------------------------------------------------------
    |
    | Networks servers of every location may not open connections to, e.g.
    | the panel's own address, besides the Proxmox nodes and each location's
    | protected networks. Comma-separated CIDRs or addresses.
    |
    */

    'protected_networks' => array_values(array_filter(array_map('trim', explode(',', (string) env('PROXMOTION_PROTECTED_NETWORKS', ''))))),

    /*
    |--------------------------------------------------------------------------
    | Drift reports (docs/adr/0005)
    |--------------------------------------------------------------------------
    |
    | Customers may shut their servers down themselves, so an active server
    | whose VM is stopped is only reported after this many minutes.
    |
    */

    'drift' => [
        'stopped_grace_minutes' => (int) env('PROXMOTION_DRIFT_STOPPED_GRACE_MINUTES', 60),
    ],

    'console' => [
        'proxy_secret' => env('CONSOLE_PROXY_SECRET'),
        'websocket_url' => env('CONSOLE_WEBSOCKET_URL'),
        'ticket_seconds' => 15,
        'max_minutes' => (int) env('CONSOLE_MAX_MINUTES', 60),
        'idle_minutes' => (int) env('CONSOLE_IDLE_MINUTES', 15),
    ],

];
