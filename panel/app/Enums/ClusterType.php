<?php

declare(strict_types=1);

namespace App\Enums;

enum ClusterType: string
{
    case Standalone = 'standalone';
    case Cluster = 'cluster';
}
