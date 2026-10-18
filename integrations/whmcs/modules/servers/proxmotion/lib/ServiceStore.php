<?php

namespace Proxmotion\Whmcs;

/**
 * What the addon hooks read from WHMCS (WhmcsServiceStore; faked in the tests).
 */
interface ServiceStore
{
    /**
     * Module parameters of a service of this module, as WHMCS passes them to
     * module functions (serviceid, userid, domain, configoption1-6 and the
     * server's connection with the password decrypted), or null when the
     * service does not use this module.
     *
     * @return array<string, mixed>|null
     */
    public function moduleParams(int $serviceId): ?array;

    /**
     * A line in the WHMCS activity log.
     */
    public function log(string $message, int $userId): void;
}
