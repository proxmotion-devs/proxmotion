<?php

declare(strict_types=1);

namespace App\Provisioning\Exceptions;

/**
 * Another task is still queued or running for the server; try again later.
 */
class TaskAlreadyRunning extends ProvisioningException
{
    public function __construct()
    {
        parent::__construct('Another task is still running for this server.');
    }
}
