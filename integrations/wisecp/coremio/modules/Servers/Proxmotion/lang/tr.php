<?php

return [
    // Server form (config.php)
    'server-ip-label' => 'Panel adresi',
    'server-ip-placeholder' => 'panel.example.com',
    'username-label' => 'Kullanıcı adı (kullanılmıyor)',
    'username-placeholder' => 'proxmotion',
    'password-label' => 'API token',
    'password-placeholder' => 'pmx_…',
    'field-webhook-secret' => 'Webhook imza anahtarı',
    'field-webhook-secret-desc' => 'İsteğe bağlı: WISECP adresinizin sonuna /api/v1/proxmotion/webhook eklenmiş adrese gönderen bir Proxmotion webhook\'unun imza anahtarı (whsec_…).',

    // Add-on values
    'addon-extra-cpu-cores' => 'Ek vCPU çekirdeği',
    'addon-extra-memory-gib' => 'Ek bellek (GB)',
    'addon-extra-disk-gib' => 'Ek disk (GB)',
    'addon-extra-ipv4-count' => 'Ek IPv4 adresi',
    'addon-extra-ipv6-count' => 'Ek IPv6 adresi',
    'addon-extra-port-speed-mbps' => 'Ek port hızı (Mbps)',
    'addon-extra-traffic-gb' => 'Ek aylık trafik (GB)',

    // Order answers
    'req-os-template' => 'İşletim sistemi',
    'req-os-template-desc' => 'Seçenek değerleri: Proxmotion\'daki OS şablonu kimlikleri.',
    'req-location' => 'Lokasyon',
    'req-location-desc' => 'Seçenek değerleri: Proxmotion\'daki lokasyon kimlikleri.',
    'req-hostname' => 'Sunucu adı',
    'req-hostname-desc' => 'Sunucunun adı, örn. vps1.example.com.',
    'req-ssh-key' => 'SSH açık anahtarı',
    'req-ssh-key-desc' => 'root için eklenir (ed25519, ECDSA veya en az 2048 bit RSA).',
    'req-root-password' => 'Root şifresi',
    'req-root-password-desc' => '12–128 karakter; aksi halde Proxmotion bir şifre oluşturur.',

    // Product settings
    'setting_package' => 'Paket',
    'setting_location' => 'Lokasyon',
    'setting_os_template' => 'OS şablonu',
    'setting_network_model' => 'Ağ modeli',
    'setting_reboot_after_upgrade' => 'Yükseltmeden sonra yeniden başlat',
    'setting_reboot_after_upgrade_desc' => 'Yükseltme veya düşürme CPU ya da belleği değiştirdiğinde sunucuyu hemen yeniden başlat.',
    'setting_cpu_cores' => 'vCPU çekirdeği',
    'setting_memory_gib' => 'Bellek (GB)',
    'setting_disk_gib' => 'Disk (GB)',
    'setting_ipv4_count' => 'IPv4 adresi',
    'setting_ipv6_count' => 'IPv6 adresi',
    'setting_port_speed_mbps' => 'Port hızı (Mbps)',
    'setting_traffic_gb' => 'Aylık trafik (GB)',
    'setting_resource_desc' => 'Boş: paketin değeri. Paket yoksa zorunlu (vCPU, bellek, disk).',
    'no_package' => 'Paket yok (aşağıdaki kaynaklar)',
    'network_default' => 'Varsayılan (paketin ilk modeli)',
    'network_bridged' => 'Bridged',
    'network_routed' => 'Routed',
    'lists_unavailable' => 'Liste Proxmotion\'dan alınamadı (:error); kimliği girin.',

    // Messages
    'no_server_returned' => 'Proxmotion siparişi kabul etti ama bir sunucu döndürmedi.',
    'password_length' => 'Root şifresi 12–128 karakter olmalı.',
    'password_not_set' => 'Proxmotion root şifresini ayarlayamadı. Daha sonra tekrar deneyin.',
    'cpu_cores' => ':count vCPU',
    'unknown_os_template' => 'Listelenen işletim sistemlerinden birini seçin.',
    'reinstall_started' => 'Yeniden kurulum başladı. Bittiğinde yeni root şifresi serviste görünür.',
    'hostname_invalid' => 'Geçerli bir sunucu adı girin, örn. vps1.example.com.',
    'not_found' => 'Bu servis için Proxmotion\'da sunucu bulunamadı.',
    'busy' => 'Proxmotion bu sunucu üzerinde hâlâ çalışıyor. Birkaç dakika sonra tekrar deneyin.',
    'not_active' => 'Sunucu aktif değil.',
];
