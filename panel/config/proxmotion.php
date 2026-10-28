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

    /*
    |--------------------------------------------------------------------------
    | Storage health (docs/adr/0047)
    |--------------------------------------------------------------------------
    |
    | A storage, an LVM thin pool's metadata or a disk's rated life used at or
    | above these per cents is a warning, then critical.
    |
    */

    'storage' => [
        'warning_percent' => (int) env('PROXMOTION_STORAGE_WARNING_PERCENT', 85),
        'critical_percent' => (int) env('PROXMOTION_STORAGE_CRITICAL_PERCENT', 95),
    ],

    'console' => [
        'proxy_secret' => env('CONSOLE_PROXY_SECRET'),
        'websocket_url' => env('CONSOLE_WEBSOCKET_URL'),
        'ticket_seconds' => 15,
        'max_minutes' => (int) env('CONSOLE_MAX_MINUTES', 60),
        'idle_minutes' => (int) env('CONSOLE_IDLE_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Traffic quotas and usage limits (docs/adr/0039)
    |--------------------------------------------------------------------------
    |
    | Traffic is counted per month from traffic_reset_day (1–28) on;
    | traffic_counted is both (in + out), out or in. A server over its quota
    | is suspended when traffic_action is suspend (none: only recorded).
    |
    | A usage limit (CPU and memory in per cent, disk in MB/s, network in
    | Mbps; empty: off) is exceeded when the server's average over
    | window_minutes reaches it; then action runs: none, stop or suspend.
    |
    */

    'usage' => [
        'traffic_reset_day' => (int) env('PROXMOTION_TRAFFIC_RESET_DAY', 1),
        'traffic_counted' => (string) env('PROXMOTION_TRAFFIC_COUNTED', 'both'),
        'traffic_action' => (string) env('PROXMOTION_TRAFFIC_OVERRUN_ACTION', 'none'),
        'cpu_percent' => env('PROXMOTION_USAGE_CPU_PERCENT') !== null && env('PROXMOTION_USAGE_CPU_PERCENT') !== '' ? (int) env('PROXMOTION_USAGE_CPU_PERCENT') : null,
        'memory_percent' => env('PROXMOTION_USAGE_MEMORY_PERCENT') !== null && env('PROXMOTION_USAGE_MEMORY_PERCENT') !== '' ? (int) env('PROXMOTION_USAGE_MEMORY_PERCENT') : null,
        'disk_mb_s' => env('PROXMOTION_USAGE_DISK_MB_S') !== null && env('PROXMOTION_USAGE_DISK_MB_S') !== '' ? (int) env('PROXMOTION_USAGE_DISK_MB_S') : null,
        'network_mbps' => env('PROXMOTION_USAGE_NETWORK_MBPS') !== null && env('PROXMOTION_USAGE_NETWORK_MBPS') !== '' ? (int) env('PROXMOTION_USAGE_NETWORK_MBPS') : null,
        'window_minutes' => (int) env('PROXMOTION_USAGE_WINDOW_MINUTES', 60),
        'action' => (string) env('PROXMOTION_USAGE_ACTION', 'none'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Generated root passwords
    |--------------------------------------------------------------------------
    |
    | Used when the panel makes up a root password: a new server ordered
    | without one, a reinstall, a reset without a chosen password. length is
    | the random part (12–64); characters is lower (letters and digits),
    | mixed (also capitals) or symbols (also !@#%+=.-_). prefix and suffix
    | surround the random part.
    |
    */

    'passwords' => [
        'length' => (int) env('PROXMOTION_PASSWORD_LENGTH', 20),
        'characters' => (string) env('PROXMOTION_PASSWORD_CHARACTERS', 'mixed'),
        'prefix' => (string) env('PROXMOTION_PASSWORD_PREFIX', '') === '' ? null : (string) env('PROXMOTION_PASSWORD_PREFIX'),
        'suffix' => (string) env('PROXMOTION_PASSWORD_SUFFIX', '') === '' ? null : (string) env('PROXMOTION_PASSWORD_SUFFIX'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email notifications (docs/adr/0044)
    |--------------------------------------------------------------------------
    |
    | Administrators get email about failures and other events they choose
    | under Settings > Notifications; recipients also get every event. Events
    | a scheduled command may raise again (drift, unreachable clusters, failed
    | webhooks) are sent once per throttle_minutes. The mail transport is
    | Laravel's own (MAIL_* in .env) and is never set on Panel settings.
    |
    */

    'notifications' => [
        'enabled' => (bool) env('PROXMOTION_NOTIFICATIONS', false),
        'recipients' => array_values(array_filter(array_map('trim', explode(',', (string) env('PROXMOTION_NOTIFICATION_RECIPIENTS', ''))))),
        'throttle_minutes' => (int) env('PROXMOTION_NOTIFICATION_THROTTLE_MINUTES', 60),
    ],

];
