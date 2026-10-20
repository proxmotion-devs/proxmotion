<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ways Proxmox can differ from the panel (ADR 0005).
 */
enum DriftType: string
{
    /** An active or suspended server's VM is not on the cluster. */
    case MissingVm = 'missing_vm';

    /** The VM runs on another node than recorded (migrated outside the panel). */
    case NodeMismatch = 'node_mismatch';

    /** Cores, memory or disk differ from the server's recorded resources. */
    case ResourceMismatch = 'resource_mismatch';

    /** A suspended server's VM runs, or an active server's VM has been stopped. */
    case PowerMismatch = 'power_mismatch';

    /** A VM in the panel's resource pool that is neither a server nor a template. */
    case UnmanagedVm = 'unmanaged_vm';

    /** The VM firewall no longer stops spoofing: a filter or net0's firewall flag is off, or the IP set differs (docs/adr/0033). */
    case FirewallMismatch = 'firewall_mismatch';
}
