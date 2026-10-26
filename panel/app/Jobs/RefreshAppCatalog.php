<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Apps\Catalog\AppCatalog;
use App\Domain\Apps\Catalog\RecipeSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads the official recipe source again on schedule (docs/adr/0041). The App
 * Store's *Refresh* button does the same at once.
 */
class RefreshAppCatalog implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function handle(AppCatalog $catalog): void
    {
        $catalog->refresh(RecipeSource::official());
    }
}
