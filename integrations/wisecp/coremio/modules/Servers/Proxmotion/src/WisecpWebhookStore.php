<?php

declare(strict_types=1);

namespace Proxmotion\Wisecp;

use Throwable;

/**
 * The webhook receiver's data in WISECP. Seen event ids are kept for a week
 * in proxmotion_webhook_events (Proxmotion retries an event for about 22 hours).
 */
final class WisecpWebhookStore implements WebhookStore
{
    public const MODULE = 'Proxmotion';

    public const EVENTS_TABLE = 'proxmotion_webhook_events';

    public const KEEP_EVENTS_DAYS = 7;

    public function servers(): array
    {
        $fieldDefinitions = (array) ((include dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php')['fields'] ?? []);

        \WDB::select()->from('servers')->where('type', '=', self::MODULE, '&&')->where('status', '=', 'active')->build();
        $servers = [];

        foreach (\WDB::fetch_assoc() ?: [] as $row) {
            $fields = is_string($row['fields'] ?? null) ? (json_decode($row['fields'], true) ?: []) : [];
            $row['fields'] = \ServerModule::crypt_decode_fields(is_array($fields) ? $fields : [], $fieldDefinitions);
            $row['password'] = self::decrypt((string) ($row['password'] ?? ''));
            $row['id'] = (int) $row['id'];
            $servers[] = $row;
        }

        return $servers;
    }

    public function claimEvent(string $eventId): bool
    {
        $this->createEventsTable();

        \WDB::delete(self::EVENTS_TABLE)->where('received_at', '<', date('Y-m-d H:i:s', time() - self::KEEP_EVENTS_DAYS * 86400))->run();

        try {
            \WDB::insert(self::EVENTS_TABLE, ['event_id' => $eventId, 'received_at' => date('Y-m-d H:i:s')]);
        } catch (Throwable) {
            // The primary key: this id was already received.
            return false;
        }

        return true;
    }

    public function releaseEvent(string $eventId): void
    {
        \WDB::delete(self::EVENTS_TABLE)->where('event_id', '=', $eventId)->run();
    }

    public function service(int $serviceId, array $serverIds): ?array
    {
        $service = \Services::get($serviceId, '', true);

        if (! $service || ($service['module'] ?? '') !== self::MODULE) {
            return null;
        }

        $options = is_array($service['options'] ?? null) ? $service['options'] : [];

        if (! in_array((int) ($options['server_id'] ?? 0), $serverIds, true)) {
            return null;
        }

        return [
            'id' => (int) $service['id'],
            'owner_id' => (int) $service['owner_id'],
            'status' => (string) $service['status'],
            'options' => $options,
        ];
    }

    public function changeStatus(int $serviceId, string $status, string $reason): void
    {
        // Without apply_on_module, WISECP does not call the module back.
        \Services::change_status($serviceId, $status, ['reason' => $reason, 'module_completed' => true]);
    }

    public function updateOptions(int $serviceId, array $values): void
    {
        $service = \Services::get($serviceId, '', true);
        $options = is_array($service['options'] ?? null) ? $service['options'] : [];

        \Services::set($serviceId, ['options' => array_replace($options, $values)]);
    }

    public function history(int $serviceId, string $message): void
    {
        \Services::add_history(0, $serviceId, 'proxmotion-webhook', ['message' => $message]);
    }

    public function notifyAdmins(int $serviceId, string $message): void
    {
        // WISECP has no general admin message: the activation failure notice carries the text.
        try {
            \Notification::dispatch('admin-messages', 'failed-service-activation', [
                'entity' => \Services::get($serviceId, '', true),
                'error_message' => $message,
            ]);
        } catch (Throwable $e) {
            // The history line is still written; a failed e-mail must not repeat the event.
            $this->log('webhook notification failed', '', $e->getMessage());
        }
    }

    public function log(string $action, string $request, string $response): void
    {
        \Modules::save_log('Servers', self::MODULE, $action, $request, $response);
    }

    /**
     * A server's password (the API token) as WISECP decrypts it for a module.
     */
    private static function decrypt(string $value): string
    {
        $key = \Config::get('crypt/user');

        if ($value === '' || ! \Crypt::is_encrypted($value, $key)) {
            return $value;
        }

        $decoded = \Crypt::decode($value, $key);

        return is_string($decoded) && $decoded !== '' ? $decoded : $value;
    }

    private function createEventsTable(): void
    {
        if (\WDB::hasTable(self::EVENTS_TABLE)) {
            return;
        }

        \WDB::exec('CREATE TABLE IF NOT EXISTS `' . self::EVENTS_TABLE . '` (
            `event_id` varchar(64) NOT NULL,
            `received_at` datetime NOT NULL,
            PRIMARY KEY (`event_id`),
            KEY `received_at` (`received_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
}
