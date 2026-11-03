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
     * @param  'backups_not_supported'|'no_backup_storage'|'backup_slots_full'|'server_not_active'  $error
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

    /**
     * Scheduled backups only (docs/adr/0051 §6): suspended and other servers are skipped.
     */
    public static function notActive(): self
    {
        return new self('Scheduled backups are taken only while the server is active.', 'server_not_active');
    }

    /**
     * Scheduled backups only (docs/adr/0051 §6): they never delete a manual backup.
     */
    public static function manualSlotsFull(): self
    {
        return new self('Manual backups fill every backup slot of this server, so no scheduled backup is taken. Delete one of them first.', 'backup_slots_full');
    }
}
