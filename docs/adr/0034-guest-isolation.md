# 34. Guest isolation

- **Status:** Accepted
- **Date:** 2026-10-21

## Context

ADR 0033 stops a server from pretending to be someone else (IP, MAC, ARP, router advertisements, DHCP). It does not stop a server from **talking** to others:

- Servers whose addresses come from one bridged pool share a subnet. Packets between them go straight from one VM to the other through the bridge: no router sees them, so a customer can scan or attack a neighbour's services without anything in the way.
- Every server can reach the Proxmox nodes (their API on 8006, SSH) and anything else on the management network, as long as it is routed. The nodes' addresses are often in or next to the customer pools.
- Nothing limits how much CPU time or disk throughput one server takes from the others on its node beyond its core count.

The maintainers want servers to be isolated from each other and from the infrastructure by default, with the Proxmox API only (no host shell, `AGENTS.md` §3.3.8) and without new privileges for the panel's token.

## Decision

### 1. Neighbours cannot reach each other

- `ip_pools.isolate_guests` (default **off**, also for existing pools — see *Default* below) says whether servers with addresses of the pool may talk to each other directly. It is a checkbox on the pool form.
- Every server's VM firewall gets three IP sets, created with the anti-spoofing options (ADR 0033) and kept equal to what the panel computes (`GuestIsolation`):
  - `pm-isolated`: the networks of the server's pools that isolate guests;
  - `pm-gateways`: those pools' gateways;
  - `pm-protected`: the protected networks (§2).
- And five rules, recognised by their comments and placed above every other rule, in this order:

  | # | Rule | Why |
  |---|---|---|
  | 1 | `out DROP dest=+pm-protected` | §2; above the gateway rule, because on routed setups the gateway is often a node |
  | 2 | `out ACCEPT dest=+pm-gateways` | the gateway stays reachable (ping, ICMP errors) |
  | 3 | `in ACCEPT source=+pm-gateways` | |
  | 4 | `out DROP dest=+pm-isolated` | no direct traffic to a neighbour |
  | 5 | `in DROP source=+pm-isolated` | none from a neighbour either (also adopted VMs without these rules) |

- The rules match by destination, not by path: two servers in one isolated pool cannot reach each other at all, also not through the gateway, also when both belong to one customer. Servers in different pools (or behind routed `/32`s of different pools) still reach each other through the router. A pool whose customers must reach each other directly turns isolation off; a private network per customer would be a separate feature (not planned yet).
- ARP still works between neighbours (ebtables only checks that a server answers for its own addresses, ADR 0033); only IP traffic is dropped.
- **Only the rules a server needs** are written, and a rule never refers to an empty IP set: rule 1 only with protected networks, rules 4–5 only when one of its pools isolates guests, rules 2–3 only then and with a gateway. Rules and IP sets a server no longer needs — its pool stopped isolating, its address moved to a pool that does not isolate — are deleted (the set's entries first, then the set), so turning isolation off really removes it from the VM firewall.
- Proxmox puts a rule added without `pos` at the top, so the panel adds the rules from the last to the first. When one is missing, extra, disabled or out of order, all of ours are deleted and added again; rules administrators added themselves are left alone (below ours).

**Default.** Neighbour isolation is off unless an administrator turns it on for a pool. Most providers (Hetzner, DigitalOcean) and panels (VirtFusion's network filters, 2026-10-21) stop spoofing but let servers of one subnet reach each other; anti-spoofing (ADR 0033) already keeps a server from taking a neighbour's addresses, and blocking all traffic between them, also between one customer's servers, would surprise customers. The protected networks (§2) are not affected by it.

### 2. Protected networks

- `pm-protected` holds: the addresses the sync learns from the nodes' bridges in the server's location (the same list `ProtectedAddresses::nodeAddresses()` already keeps out of pools), the location's **protected networks** (`locations.protected_networks`, a list of CIDRs on the location form, e.g. a management VLAN or the panel's network), and `PROXMOTION_PROTECTED_NETWORKS` (comma-separated CIDRs for every location, e.g. the panel's own address).
- A server cannot open connections to these addresses. Connections they open to the server still work (the panel never connects to servers; the guest agent does not use the network).
- Nothing is written to the datacenter firewall: that needs `Sys.Modify` (ADR 0033 §1), and a per-VM IP set keeps the token's privileges unchanged.

### 3. Resource limits

`packages` and `servers` get four optional limits, empty = unlimited:

| Column | Proxmox | Meaning |
|---|---|---|
| `cpu_limit` | `cpulimit` | at most this many cores' worth of CPU time (e.g. `1.5`), even with more vCPUs |
| `cpu_units` | `cpuunits` | relative CPU weight when the node is busy (Proxmox's default is 100 with cgroup v2) |
| `disk_mb_s` | `mbps_rd`, `mbps_wr` on the boot disk | read and write throughput, MB/s each |
| `disk_iops` | `iops_rd`, `iops_wr` on the boot disk | read and write operations per second each |

Servers take them from their package when they are created and when their package changes; the admin *Custom* create form and the *Change resources* dialog take them by hand. The billing API accepts no overrides for them (they come from the package, `null` without one), so API v1 and the billing modules are unchanged. They are written with cores and memory (create, reinstall, change resources); Proxmox applies `cpulimit`, `cpuunits` and disk throttling to a running VM at once (unverified).

### 4. Existing servers

Nothing changes on its own. `FirewallChecker` (ADR 0033 §3) also reports `isolation_rules_missing` (a rule missing, disabled or out of order) and `isolation_sets_differ` (an IP set differs from the panel) as problems of the `firewall_mismatch` drift. *Enable anti-spoofing* writes the sets and rules too, so existing servers get isolation once an administrator presses it. Turning a pool's isolation off or changing protected networks opens drifts on the servers concerned the same way. Resource limits of existing servers change only with *Change resources*.

## Consequences

- Servers of a pool with isolation turned on cannot reach each other's public addresses, also when one customer owns them; it is off by default for that reason.
- Servers cannot open connections to the Proxmox nodes, which also means no DNS or NTP from a node that is the servers' gateway.
- Five more rules and three IP sets per VM; the sync reads three more IP sets per checked server (up to 60 more calls per cluster every five minutes).
- Not verified on a real Proxmox yet: that rules added without `pos` land at the top, `DELETE .../firewall/ipset/{name}` of an emptied set, the IP sets mixing IPv4 and IPv6 entries, the order of the built-in NDP rules against rule 5, and the limits applied to a running VM.
