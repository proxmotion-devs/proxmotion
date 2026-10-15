<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Enums\NetworkModel;
use App\Models\Location;
use App\Models\OsTemplate;
use App\Models\Package;
use App\Models\User;
use SensitiveParameter;

/**
 * Everything needed to order a new server.
 */
final readonly class NewServer
{
    public function __construct(
        public User $owner,
        public Location $location,
        public Package $package,
        public OsTemplate $osTemplate,
        public NetworkModel $networkModel,
        public string $hostname,
        #[SensitiveParameter] public string $rootPassword,
        public ?string $sshKeys = null,
        public ?string $externalId = null,
    ) {}
}
