# 57. Nodes removed from a cluster

- **Status:** Accepted (built 2026-11-05)
- **Date:** 2026-11-05

## Context

`ClusterSynchronizer` mirrors the nodes `GET /nodes` lists. A node Proxmox stopped listing, e.g. after `pvecm delnode` or a rename, was only given the status `unknown` ("Not reported"), so its servers keep a node (ADR 0005: the panel's database is the source of truth, nothing is deleted on a sync). Nothing ever removed it: the panel had no action for it, so a node taken out of a cluster stayed on the cluster page for good. Its storages stayed `is_available` and its bridges `is_active`, because the sync only touched those of the nodes Proxmox listed: the create wizard offered them, the placement group form let administrators add the node, the capacity report counted its CPU, memory and disks, and its bridge addresses stayed protected and reserved in IP pools.

A node row cannot simply be deleted: `servers.node_id` is restricted, and terminated servers keep their node as history, as do server moves.

That `GET /nodes` drops a node after `pvecm delnode` is believed from Proxmox's source (it lists the corosync node list) and has not been checked on a real Proxmox VE.

## Decision

1. **The sync takes a missing node's storages and bridges with it.** Nodes Proxmox no longer lists stay `unknown`, and their storages become unavailable and their bridges inactive in the same transaction.
2. **Administrators can remove such a node from the panel** (*Delete* on its card, `DELETE /admin/nodes/{node}`, `NodeRemover`). Only a node with status `unknown`, without servers that are not terminated, and without a move from or to it that is queued, running or failed after Proxmox had moved the VM and not yet recorded. Nothing is changed on Proxmox. Audited as `node.removed` with its name, cluster and bridge addresses.
3. **Removal is a soft delete** (`nodes.deleted_at`). Every query on nodes leaves it out (placement, forms, reports, protected addresses, the sync's own lists), while `Server::node()` and the move's `sourceNode()` / `targetNode()` still find it for terminated servers and past moves. Its storages, bridges and disks stay as rows of the removed node.
4. **The addresses the sync reserved for it are released**: addresses on its bridges that no other node of the location has, still unassigned and reserved with the sync's note `Proxmox node`, are freed.
5. **It comes back when Proxmox lists it again** (rejoined, or the node was renamed back): the sync restores it with its placement settings instead of creating a second row (`nodes` keeps its unique `cluster_id, name`).
6. **Until removed, a node with status `unknown`** is left out of the create wizard and of the capacity report's capacity (its booked servers still count), and is listed in the placement group form only while a group still has it.

Two related fixes of the sync come with it: an empty `GET /nodes` answer fails the sync (Proxmox always lists the node that answers) instead of marking every node `unknown`, and a node whose `/network` call fails keeps its last bridges instead of failing the sync of the whole cluster. `infra:sync` goes on with the other clusters after any error of one, not only a Proxmox error.

## Consequences

- A node removed by mistake while Proxmox does not list it comes back by itself once Proxmox lists it again.
- Servers left on a node Proxmox no longer lists (moved in Proxmox by hand, drift `node_mismatch`) block its removal until an administrator resolves them.
- Not done: a node renamed in Proxmox is a new node; its servers are not moved to it.
