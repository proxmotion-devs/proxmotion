<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Review state of a stored recipe version (docs/adr/0016 §2, 0017 §4). Only
 * approved versions are installed; bundled recipes are approved on apps:sync.
 */
enum AppRecipeVersionStatus: string
{
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
