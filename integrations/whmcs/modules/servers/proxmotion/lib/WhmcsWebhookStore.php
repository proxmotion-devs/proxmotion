<?php

namespace Proxmotion\Whmcs;

use Exception;
use Illuminate\Database\Schema\Blueprint;
use WHMCS\Database\Capsule;

/**
 * The webhook receiver's data in the WHMCS database. Seen event ids are kept
 * for a week in mod_proxmotion_webhook_events (Proxmotion retries an event
 * for about 22 hours).
 */
class WhmcsWebhookStore implements WebhookStore
{
    const EVENTS_TABLE = 'mod_proxmotion_webhook_events';

    const KEEP_EVENTS_DAYS = 7;

    public function servers(): array
    {
        $servers = [];

        foreach (Capsule::table('tblservers')->where('type', 'proxmotion')->where('disabled', 0)->get() as $row) {
            $password = '';

            if ((string) $row->password !== '') {
                $decrypted = localAPI('DecryptPassword', ['password2' => (string) $row->password]);
                $password = isset($decrypted['password']) ? (string) $decrypted['password'] : '';
            }

            $servers[] = [
                'serverid' => (int) $row->id,
                'serverhostname' => (string) $row->hostname,
                'serverip' => (string) $row->ipaddress,
                'serversecure' => (bool) $row->secure,
                'serverport' => (string) $row->port,
                'serverpassword' => $password,
                'serveraccesshash' => (string) $row->accesshash,
            ];
        }

        return $servers;
    }

    public function claimEvent(string $eventId): bool
    {
        $this->createEventsTable();

        Capsule::table(self::EVENTS_TABLE)
            ->where('received_at', '<', date('Y-m-d H:i:s', time() - self::KEEP_EVENTS_DAYS * 86400))
            ->delete();

        try {
            Capsule::table(self::EVENTS_TABLE)->insert(['event_id' => $eventId, 'received_at' => date('Y-m-d H:i:s')]);
        } catch (Exception $e) {
            // The primary key: this id was already received.
            return false;
        }

        return true;
    }

    public function releaseEvent(string $eventId): void
    {
        Capsule::table(self::EVENTS_TABLE)->where('event_id', $eventId)->delete();
    }

    public function service(int $serviceId, array $serverIds): ?array
    {
        if ($serverIds === []) {
            return null;
        }

        $row = Capsule::table('tblhosting')->where('id', $serviceId)->whereIn('server', $serverIds)->first();

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'userid' => (int) $row->userid,
            'domain' => (string) $row->domain,
            'status' => (string) $row->domainstatus,
        ];
    }

    private function createEventsTable(): void
    {
        if (Capsule::schema()->hasTable(self::EVENTS_TABLE)) {
            return;
        }

        Capsule::schema()->create(self::EVENTS_TABLE, function (Blueprint $table) {
            $table->string('event_id', 64)->primary();
            $table->dateTime('received_at')->index();
        });
    }
}
