<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Enums\NetworkModel;
use App\Models\AppRecipe;
use App\Models\BillingSystem;
use App\Models\IpPool;
use App\Models\Location;
use App\Models\Node;
use App\Models\OsTemplate;
use App\Models\Package;
use App\Models\PlacementGroup;
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
        /** Null: generated from the location's template once the addresses are assigned (docs/adr/0031). */
        public ?string $hostname,
        #[SensitiveParameter] public string $rootPassword,
        public ?Package $package = null,
        public ?string $sshKeys = null,
        public ?string $externalId = null,
        public ?string $externalClientId = null,
        /** The billing system that owns the server and its billing ids. */
        public ?BillingSystem $billingSystem = null,
        /** A free address to use as the primary IPv4 address instead of the next free one (docs/adr/0011). */
        public ?string $primaryIpv4 = null,
        /** The IPv4 pool to take the addresses from instead of the first with room. */
        public ?IpPool $ipv4Pool = null,
        /** @var list<string> free addresses of the same pool given besides the primary; the rest are the next free ones */
        public array $additionalIpv4 = [],
        /** An app to install once the server has booted (docs/adr/0016 §5). */
        public ?AppRecipe $app = null,
        /** @var array<array-key, mixed> the app's inputs, not validated yet */
        #[SensitiveParameter] public array $appInputs = [],
        /** A placement group chosen by an administrator: automatic placement inside it (docs/adr/0020). */
        public ?PlacementGroup $placementGroup = null,
        /** A node chosen by an administrator instead of automatic placement. */
        public ?Node $node = null,
        /** The name of a storage of the chosen node; null takes the one with the most room. */
        public ?string $storage = null,
    ) {}
}
