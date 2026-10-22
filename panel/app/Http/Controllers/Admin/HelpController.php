<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Settings\PanelSettings;
use App\Http\Controllers\Controller;
use App\Models\BillingSystem;
use App\Models\Cluster;
use App\Models\IpPool;
use App\Models\Location;
use App\Models\OsTemplate;
use App\Models\Package;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Help page: the setup order with what is already done, and short
 * how-tos for the pages administrators ask about most.
 */
class HelpController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('admin/Help', [
            'setup' => [
                'locations' => Location::query()->exists(),
                'clusters' => Cluster::query()->exists(),
                'ip_pools' => IpPool::query()->exists(),
                'os_templates' => OsTemplate::query()->exists(),
                'packages' => Package::query()->exists(),
                'billing_systems' => BillingSystem::query()->exists(),
            ],
            'docsUrl' => PanelSettings::get('docs_url'),
        ]);
    }
}
