# 20. Placement groups

- **Status:** Accepted
- **Date:** 2026-10-18
- **Builds on:** AGENTS.md 3.6 (placement), the package cluster limit (`cluster_package`), manual node and storage choice on the admin create form.

## Context

Placement picks the least-used node of the ordered location, optionally limited to the clusters a package names, and takes the storage marked *Use for servers* with the most room. Administrators can also choose a node and storage by hand. What is missing is the middle ground: a named set of hardware, such as "NVMe EPYC" or "HDD storage nodes", that a package or a new server targets, with placement still choosing automatically inside it. Clusters are too coarse for that: one cluster often mixes node generations and storage types.

Proxmox's own resource pools are not the answer. The panel's API token has rights only on one pool per cluster (`clusters.resource_pool`). Creating pools would need `Pool.Allocate`, and the permission check reports that as an excess privilege. Proxmox pools also say nothing about which disks a VM may use.

## Decision

1. **A placement group** (`placement_groups`) is a panel-only record: a name, an optional description and a location. Its members are nodes of that location's clusters (`node_placement_group`) and, optionally, storages of those nodes (`placement_group_storage`). Nothing is written to Proxmox, and the token's rights are unchanged.
2. **Storages:** a group that lists storages places disks only on those storages. They must take VM disks and be available, but need not be marked *Use for servers*: listing them is the administrator's explicit choice. A group without storages uses each node's *Use for servers* storages. A node of a group with listed storages, none of them on that node, gets no server through that group.
3. **Packages** may name placement groups (`package_placement_group`), like they name clusters. When they do, servers of the package go only to nodes of its groups in the ordered location. If none of its groups is in that location, the order fails with a clear message. Package clusters still apply as well: a node must pass both limits.
4. **The admin create form** offers three placements: automatic, a placement group of the location (automatic inside it), or a node chosen by hand. Groups and nodes outside the package's groups are shown but cannot be chosen, and the server refuses them when the form is bypassed. Inside a group, nodes in maintenance are skipped, as in automatic placement.
5. **Deleting a group that a package names is refused.** Otherwise the package would silently fall back to "any node". Nodes removed by the cluster sync leave their groups; an empty group places nothing.
6. Placement groups only decide where a new server goes. Existing servers are not moved when a group changes, and the server does not remember the group it was placed through.

## Consequences

- Providers can sell plans per hardware class without splitting clusters.
- Two overlapping limits on packages (clusters and groups). Clusters stay for existing setups; groups are the finer tool.
- The billing API has no `placement_group` parameter yet: billing systems target groups through packages. Adding one later is a backwards-compatible change.
- Changing a server's package does not check the new package's clusters or groups (unchanged from before).
