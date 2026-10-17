<?php

return [
    // Server form (config.php)
    'server-ip-label' => 'Panel address',
    'server-ip-placeholder' => 'panel.example.com',
    'username-label' => 'Username (not used)',
    'username-placeholder' => 'proxmotion',
    'password-label' => 'API token',
    'password-placeholder' => 'pmx_…',
    'field-webhook-secret' => 'Webhook signing secret',
    'field-webhook-secret-desc' => 'Optional: the signing secret (whsec_…) of a Proxmotion webhook to your WISECP address followed by /api/v1/proxmotion/webhook.',

    // Add-on values
    'addon-extra-cpu-cores' => 'Extra vCPU cores',
    'addon-extra-memory-gib' => 'Extra memory (GB)',
    'addon-extra-disk-gib' => 'Extra disk (GB)',
    'addon-extra-ipv4-count' => 'Extra IPv4 addresses',
    'addon-extra-ipv6-count' => 'Extra IPv6 addresses',
    'addon-extra-port-speed-mbps' => 'Extra port speed (Mbps)',
    'addon-extra-traffic-gb' => 'Extra monthly traffic (GB)',

    // Order answers
    'req-os-template' => 'Operating system',
    'req-os-template-desc' => 'Option values: OS template ids from Proxmotion.',
    'req-location' => 'Location',
    'req-location-desc' => 'Option values: location ids from Proxmotion.',
    'req-hostname' => 'Hostname',
    'req-hostname-desc' => 'The server\'s hostname, e.g. vps1.example.com.',
    'req-ssh-key' => 'SSH public key',
    'req-ssh-key-desc' => 'Added for root (ed25519, ECDSA or RSA of at least 2048 bits).',
    'req-root-password' => 'Root password',
    'req-root-password-desc' => '12 to 128 characters; otherwise Proxmotion generates one.',

    // Product settings
    'setting_package' => 'Package',
    'setting_location' => 'Location',
    'setting_os_template' => 'OS template',
    'setting_network_model' => 'Network model',
    'setting_reboot_after_upgrade' => 'Restart after upgrades',
    'setting_reboot_after_upgrade_desc' => 'Restart the server right away when an upgrade or downgrade changes CPU or memory.',
    'setting_cpu_cores' => 'vCPU cores',
    'setting_memory_gib' => 'Memory (GB)',
    'setting_disk_gib' => 'Disk (GB)',
    'setting_ipv4_count' => 'IPv4 addresses',
    'setting_ipv6_count' => 'IPv6 addresses',
    'setting_port_speed_mbps' => 'Port speed (Mbps)',
    'setting_traffic_gb' => 'Monthly traffic (GB)',
    'setting_resource_desc' => 'Empty: the package\'s value. Required without a package (vCPU, memory, disk).',
    'no_package' => 'No package (resources below)',
    'network_default' => 'Default (first model of the package)',
    'network_bridged' => 'Bridged',
    'network_routed' => 'Routed',
    'lists_unavailable' => 'The list could not be loaded from Proxmotion (:error); enter the id.',

    // Messages
    'no_server_returned' => 'Proxmotion accepted the order but returned no server.',
    'password_length' => 'The root password must have 12 to 128 characters.',
    'password_not_set' => 'Proxmotion could not set the root password. Try again later.',
    'cpu_cores' => ':count vCPU',
    'unknown_os_template' => 'Choose one of the listed operating systems.',
    'reinstall_started' => 'The reinstallation has started. The new root password appears on the service when it is done.',
    'hostname_invalid' => 'Enter a valid hostname, e.g. vps1.example.com.',
    'not_found' => 'No server was found in Proxmotion for this service.',
    'busy' => 'Proxmotion is still working on this server. Try again in a few minutes.',
    'not_active' => 'The server is not active.',
];
