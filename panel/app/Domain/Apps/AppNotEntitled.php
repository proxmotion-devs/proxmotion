<?php

declare(strict_types=1);

namespace App\Domain\Apps;

/**
 * A paid app that the server has no right to (docs/adr/0019): it fits the
 * server, but the billing system has to sell it first. The API answers 422;
 * the catalog still lists it, with entitled false.
 */
final class AppNotEntitled extends AppNotEligible {}
