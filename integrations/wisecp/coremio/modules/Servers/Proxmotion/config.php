<?php

/*
 * WISECP reads this file for the server form (Products → Servers), the
 * supported client area cards and tools, add-on values and order answers.
 * {lang.…} values come from lang/<language>.php.
 */
return [
    'name' => 'Proxmotion',
    'type' => 'server',
    'category' => 'virtualization',
    'use-access-hash' => false,
    'use-test-connection' => true,
    'use-port' => true,
    'not-secure-port' => 80,
    'secure-port' => 443,
    'service-relationship' => 'config.server_id',
    // The API token is WISECP's password field (always shown and required, stored
    // encrypted); lang/*.php relabels it and the unused username field.
    'fields' => [
        'webhook_secret' => [
            'type' => 'password',
            'name' => '{lang.field-webhook-secret}',
            'description' => '{lang.field-webhook-secret-desc}',
            'value' => '',
            'col_class' => 'col-md-6',
            'required' => false,
            'crypt' => true,
        ],
    ],
    'supported' => [
        'cards' => [
            'server_info' => ['hostname', 'ip_address', 'os', 'virtualization', 'location', 'status'],
            'resource_allocation' => ['cpu_cores', 'ram', 'disk_space', 'ipv4', 'ipv6'],
            'quick_access' => ['vnc-console'],
        ],
        'tools' => [
            'start-server',
            'stop-server',
            'restart-server',
            'poweroff-server',
            'reset-password',
            'reinstall-os',
            'vnc-console',
            'hostname-change',
            'reverse-dns',
        ],
    ],
    // Added to the package's values (README.md: Add-ons).
    'addon-params' => [
        'extra_cpu_cores' => ['label' => '{lang.addon-extra-cpu-cores}', 'type' => 'value'],
        'extra_memory_gib' => ['label' => '{lang.addon-extra-memory-gib}', 'type' => 'value'],
        'extra_disk_gib' => ['label' => '{lang.addon-extra-disk-gib}', 'type' => 'value'],
        'extra_ipv4_count' => ['label' => '{lang.addon-extra-ipv4-count}', 'type' => 'value'],
        'extra_ipv6_count' => ['label' => '{lang.addon-extra-ipv6-count}', 'type' => 'value'],
        'extra_port_speed_mbps' => ['label' => '{lang.addon-extra-port-speed-mbps}', 'type' => 'value'],
        'extra_traffic_gb' => ['label' => '{lang.addon-extra-traffic-gb}', 'type' => 'value'],
    ],
    // Order answers that replace the product's settings (README.md: Order answers).
    'requirement-params' => [
        'os_template' => ['label' => '{lang.req-os-template}', 'description' => '{lang.req-os-template-desc}'],
        'location' => ['label' => '{lang.req-location}', 'description' => '{lang.req-location-desc}'],
        'hostname' => ['label' => '{lang.req-hostname}', 'description' => '{lang.req-hostname-desc}'],
        'ssh_key' => ['label' => '{lang.req-ssh-key}', 'description' => '{lang.req-ssh-key-desc}'],
        'root_password' => ['label' => '{lang.req-root-password}', 'description' => '{lang.req-root-password-desc}'],
    ],
];
