<?php

declare(strict_types=1);

namespace App\Domain\Installation;

/**
 * One check of Requirements: a missing required one stops proxmotion:install
 * and proxmotion:upgrade, the others only warn (docs/adr/0052).
 */
final readonly class Requirement
{
    public function __construct(
        public string $label,
        public bool $passed,
        public bool $required = true,
        public ?string $note = null,
    ) {}
}
