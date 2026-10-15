<?php

declare(strict_types=1);

namespace App\Proxmox;

use App\Models\Cluster;
use Illuminate\Http\Client\Factory as HttpFactory;

class ProxmoxClientFactory
{
    public function __construct(private readonly HttpFactory $http) {}

    public function make(ProxmoxConnection $connection): ProxmoxClient
    {
        return new ProxmoxClient($connection, $this->http);
    }

    public function forCluster(Cluster $cluster): ProxmoxClient
    {
        return $this->make($cluster->connection());
    }
}
