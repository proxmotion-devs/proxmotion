<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Installation\SetupProgress;
use App\Domain\Settings\PanelSettings;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Help page: the setup order with what is already done, and short
 * how-tos for the pages administrators ask about most.
 */
class HelpController extends Controller
{
    public function __invoke(SetupProgress $setup): Response
    {
        return Inertia::render('admin/Help', [
            'setup' => $setup->steps(),
            'docsUrl' => PanelSettings::get('docs_url'),
        ]);
    }
}
