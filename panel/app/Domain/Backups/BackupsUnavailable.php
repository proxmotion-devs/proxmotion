<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Provisioning\Exceptions\ProvisioningException;

/**
 * The server cannot take backups at all (docs/adr/0051 §4): none in its
 * plan, or no storage of its cluster marked for backups that its node
 * reaches. The API answers 422 with $error.
 */
final class BackupsUnavailable extends ProvisioningException
{
    /**
     * @param  'backups_not_supported'|'no_backup_storage'  $error
     */
    private function __construct(string $message, public readonly string $error)
    {
        parent::__construct($message);
    }

    public static function notInPlan(): self
    {
        return new self('Backups are not part of this server\'s plan.', 'backups_not_supported');
    }

    public static function noStorage(): self
    {
        return new self('No storage that this server\'s node reaches is marked for backups.', 'no_backup_storage');
    }
}
