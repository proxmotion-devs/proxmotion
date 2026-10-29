<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Settings administrators change on the Panel settings page (docs/adr/0038).
 * A setting never saved there keeps the value of config/proxmotion.php, i.e.
 * its .env variable or default. Security and deployment settings (2FA, the
 * console proxy) stay in .env only.
 *
 * Saved values are cached until the next save, so queue workers and the
 * scheduler see a change without a restart.
 */
final class PanelSettings
{
    /**
     * Each setting and the config/proxmotion.php key it overrides.
     */
    public const array KEYS = [
        'cross_cluster_moves' => 'moves.cross_cluster',
        'move_deadline_minutes' => 'moves.deadline_minutes',
        'move_bwlimit_kib' => 'moves.bwlimit_kib',
        'min_vmid' => 'provisioning.min_vmid',
        'provisioning_deadline_minutes' => 'provisioning.deadline_minutes',
        'backup_deadline_minutes' => 'backups.deadline_minutes',
        'verify_dns_name' => 'provisioning.verify_dns_name',
        'protected_networks' => 'protected_networks',
        'drift_stopped_grace_minutes' => 'drift.stopped_grace_minutes',
        'storage_warning_percent' => 'storage.warning_percent',
        'storage_critical_percent' => 'storage.critical_percent',
        'console_max_minutes' => 'console.max_minutes',
        'console_idle_minutes' => 'console.idle_minutes',
        'docs_url' => 'docs_url',
        'traffic_reset_day' => 'usage.traffic_reset_day',
        'traffic_counted' => 'usage.traffic_counted',
        'traffic_action' => 'usage.traffic_action',
        'usage_cpu_percent' => 'usage.cpu_percent',
        'usage_memory_percent' => 'usage.memory_percent',
        'usage_disk_mb_s' => 'usage.disk_mb_s',
        'usage_network_mbps' => 'usage.network_mbps',
        'usage_window_minutes' => 'usage.window_minutes',
        'usage_action' => 'usage.action',
        'password_length' => 'passwords.length',
        'password_characters' => 'passwords.characters',
        'password_prefix' => 'passwords.prefix',
        'password_suffix' => 'passwords.suffix',
        'ssh_keys_max' => 'ssh_keys.max',
        'notifications_enabled' => 'notifications.enabled',
        'notification_recipients' => 'notifications.recipients',
        'notification_throttle_minutes' => 'notifications.throttle_minutes',
    ];

    /**
     * The pages of Panel settings, listed under it in the menu, and the
     * settings each one saves. The last one only shows the .env settings.
     */
    public const array CATEGORIES = [
        'servers' => [
            'min_vmid',
            'provisioning_deadline_minutes',
            'backup_deadline_minutes',
            'verify_dns_name',
            'password_length',
            'password_characters',
            'password_prefix',
            'password_suffix',
            'ssh_keys_max',
            'cross_cluster_moves',
            'move_deadline_minutes',
            'move_bwlimit_kib',
        ],
        'network' => ['protected_networks'],
        'usage' => [
            'traffic_reset_day',
            'traffic_counted',
            'traffic_action',
            'usage_cpu_percent',
            'usage_memory_percent',
            'usage_disk_mb_s',
            'usage_network_mbps',
            'usage_window_minutes',
            'usage_action',
        ],
        'general' => ['console_max_minutes', 'console_idle_minutes', 'drift_stopped_grace_minutes', 'storage_warning_percent', 'storage_critical_percent', 'docs_url'],
        'notifications' => ['notifications_enabled', 'notification_recipients', 'notification_throttle_minutes'],
        'environment' => [],
    ];

    private const string CACHE_KEY = 'panel-settings';

    /**
     * The saved value, else the one from config/proxmotion.php.
     */
    public static function get(string $key): mixed
    {
        $config = self::KEYS[$key] ?? throw new InvalidArgumentException("Unknown panel setting {$key}.");
        $saved = self::saved();

        return array_key_exists($key, $saved) ? $saved[$key] : config("proxmotion.{$config}");
    }

    /**
     * Every setting with the value in use.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $values = [];

        foreach (array_keys(self::KEYS) as $key) {
            $values[$key] = self::get($key);
        }

        return $values;
    }

    /**
     * The settings never saved on the page, which still follow .env.
     *
     * @return list<string>
     */
    public static function unsaved(): array
    {
        return array_values(array_diff(array_keys(self::KEYS), array_keys(self::saved())));
    }

    /**
     * Save the given settings and return what changed, as [key => [from, to]].
     *
     * @param  array<string, mixed>  $values
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function save(array $values): array
    {
        $changes = [];
        $now = now();

        DB::transaction(function () use ($values, $now, &$changes) {
            foreach ($values as $key => $value) {
                if (! array_key_exists($key, self::KEYS)) {
                    throw new InvalidArgumentException("Unknown panel setting {$key}.");
                }

                $current = self::get($key);

                if ($current !== $value) {
                    $changes[$key] = ['from' => $current, 'to' => $value];
                }

                DB::table('settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value, JSON_THROW_ON_ERROR), 'updated_at' => $now]);
            }
        });

        Cache::forget(self::CACHE_KEY);

        return $changes;
    }

    /**
     * @return array<string, mixed>
     */
    private static function saved(): array
    {
        /** @var array<string, mixed> */
        return Cache::rememberForever(self::CACHE_KEY, fn () => DB::table('settings')->pluck('value', 'key')
            ->map(fn (mixed $value) => json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR))
            ->all());
    }
}
