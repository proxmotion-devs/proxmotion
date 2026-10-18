<?php

namespace Proxmotion\Whmcs;

use WHMCS\Database\Capsule;

/**
 * Module parameters of a service read from the WHMCS database, for hooks
 * that WHMCS calls without them (addon activation and cancellation).
 */
class WhmcsServiceStore implements ServiceStore
{
    public function moduleParams(int $serviceId): ?array
    {
        $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();

        if ($service === null) {
            return null;
        }

        $product = Capsule::table('tblproducts')->where('id', $service->packageid)->first();
        $server = Capsule::table('tblservers')->where('id', $service->server)->first();

        if ($product === null || $server === null || $product->servertype !== 'proxmotion') {
            return null;
        }

        $params = [
            'serviceid' => (int) $service->id,
            'userid' => (int) $service->userid,
            'domain' => (string) $service->domain,
        ];

        for ($number = 1; $number <= 6; $number++) {
            $params['configoption' . $number] = (string) $product->{'configoption' . $number};
        }

        return array_merge($params, self::serverParams($server));
    }

    public function log(string $message, int $userId): void
    {
        logActivity($message, $userId);
    }

    /**
     * A tblservers row as module parameters, the password decrypted.
     *
     * @param \stdClass $row
     * @return array<string, mixed>
     */
    public static function serverParams($row): array
    {
        $password = '';

        if ((string) $row->password !== '') {
            $decrypted = localAPI('DecryptPassword', ['password2' => (string) $row->password]);
            $password = isset($decrypted['password']) ? (string) $decrypted['password'] : '';
        }

        return [
            'serverid' => (int) $row->id,
            'serverhostname' => (string) $row->hostname,
            'serverip' => (string) $row->ipaddress,
            'serversecure' => (bool) $row->secure,
            'serverport' => (string) $row->port,
            'serverpassword' => $password,
            'serveraccesshash' => (string) $row->accesshash,
        ];
    }
}
