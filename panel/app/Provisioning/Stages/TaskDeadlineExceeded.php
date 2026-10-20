<?php

declare(strict_types=1);

namespace App\Provisioning\Stages;

use RuntimeException;

/**
 * A staged task did not finish by its deadline (docs/adr/0032).
 */
final class TaskDeadlineExceeded extends RuntimeException
{
    public static function after(int $minutes): self
    {
        return new self("The task did not finish within {$minutes} minutes.");
    }
}
