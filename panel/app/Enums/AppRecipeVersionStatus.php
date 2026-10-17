<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * State of a stored recipe version (docs/adr/0016 §2, 0017 §4, 0018 §3).
 * New installations get the app's published version; installations keep
 * theirs when it is archived. Bundled versions are published by apps:sync,
 * custom ones by an administrator.
 */
enum AppRecipeVersionStatus: string
{
    /** Being edited in the admin editor (custom apps); never offered to customers. */
    case Draft = 'draft';

    /** The version new installations get (one per app). */
    case Published = 'published';

    /** Replaced by a newer published version; installations may still use it. */
    case Archived = 'archived';

    /** Fetched from a catalog, waiting for an administrator (docs/adr/0017). */
    case PendingReview = 'pending_review';

    case Rejected = 'rejected';
}
