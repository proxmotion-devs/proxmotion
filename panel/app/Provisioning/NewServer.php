<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Enums\NetworkModel;
use App\Models\BillingSystem;
use App\Models\Location;
use App\Models\OsTemplate;
use App\Models\Package;
use SensitiveParameter;

/**
 * Everything needed to order a new server. Resources come from $spec; the
 * package is optional (null for servers with custom resources).
 */
final readonly class NewServer
{
    public function __construct(
        public Location $location,
        public ServerSpec $spec,
        public OsTemplate $osTemplate,
        public NetworkModel $networkModel,
        public string $hostname,
        #[SensitiveParameter] public string $rootPassword,
        public ?Package $package = null,
        public ?string $sshKeys = null,
        public ?string $externalId = null,
        public ?string $externalClientId = null,
        /** The billing system that owns the server and its billing ids. */
        public ?BillingSystem $billingSystem = null,
    ) {}
}
