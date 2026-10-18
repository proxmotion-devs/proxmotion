# 20. Placement groups

- **Status:** Accepted
- **Date:** 2026-10-18 (amended the same day: §3, §4 and §7 — packages no longer name clusters; the create form places package servers automatically and custom servers in a group or on a single node)
- **Builds on:** AGENTS.md 3.6 (placement), the package cluster limit (`cluster_package`), manual node and storage choice on the admin create form.

## Context

Placement picks the least-used node of the ordered location, optionally limited to the clusters a package names, and takes the storage marked *Use for servers* with the most room. Administrators can also choose a node and storage by hand. What is missing is the middle ground: a named set of hardware, such as "NVMe EPYC" or "HDD storage nodes", that a package or a new server targets, with placement still choosing automatically inside it. Clusters are too coarse for that: one cluster often mixes node generations and storage types.

Proxmox's own resource pools are not the answer. The panel's API token has rights only on one pool per cluster (`clusters.resource_pool`). Creating pools would need `Pool.Allocate`, and the permission check reports that as an excess privilege. Proxmox pools also say nothing about which disks a VM may use.

## Decision

1. **A placement group** (`placement_groups`) is a panel-only record: a name, an optional description and a location. Its members are nodes of that location's clusters (`node_placement_group`) and, optionally, storages of those nodes (`placement_group_storage`). Nothing is written to Proxmox, and the token's rights are unchanged.
2. **Storages:** a group that lists storages places disks only on those storages. They must take VM disks and be available, but need not be marked *Use for servers*: listing them is the administrator's explicit choice. A group without storages uses each node's *Use for servers* storages. A node of a group with listed storages, none of them on that node, gets no server through that group.
3. **Packages** may name placement groups (`package_placement_group`). When they do, servers of the package go only to nodes of its groups in the ordered location. If none of its groups is in that location, the order fails with a clear message. A package without groups may use any node of the location.
4. **The admin create form** first asks for a package or a custom server. A **package** server gets exactly the package's resources and is placed like an order from the billing API: automatically within the package's groups in the location, or anywhere in the location when it names none. No placement is chosen or accepted, since the package already says where it may go. A **custom** server (no package) must be placed by the administrator: in a placement group of the location (automatic inside it) or on a single node chosen by hand, optionally with its storage. Inside a group, nodes in maintenance are skipped, as in automatic placement. Choosing a node or a group of a package's servers by hand (the earlier form) is gone; a package server that needs to go elsewhere is created as a custom server.
5. **Deleting a group that a package names is refused.** Otherwise the package would silently fall back to "any node". Nodes removed by the cluster sync leave their groups; an empty group places nothing.
6. Placement groups only decide where a new server goes. Existing servers are not moved when a group changes, and the server does not remember the group it was placed through.
7. **Packages no longer name clusters.** Placement groups replace the package cluster limit (`cluster_package`, dropped). A migration turns each cluster a package named into one placement group, "Cluster <name>", with all of that cluster's nodes and no storages, and gives it to every package that named the cluster. Packages keep placing their servers where they did.

## Consequences

- Providers can sell plans per hardware class without splitting clusters.
- One limit on packages instead of two: a cluster limit is a group with all of the cluster's nodes. Nodes added to the cluster later must be added to such a group by hand.
- The billing API has no `placement_group` parameter yet: billing systems target groups through packages. Adding one later is a backwards-compatible change.
- Changing a server's package does not check the new package's groups (unchanged from before).
