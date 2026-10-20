# 33. Anti-spoofing that works

- **Status:** Accepted
- **Date:** 2026-10-21

## Context

Since Phase 2 every server's VM firewall has `ipfilter` and `macfilter` on and an IP set `ipfilter-net0` of exactly its addresses (§7.6 of `AGENTS.md`). Reading `pve-firewall` (`src/PVE/Firewall.pm`, 2026-10-21) showed what this does and what it does not:

- **ARP** is filtered by **ebtables**: frames from a source MAC other than the interface's are dropped, and ARP packets whose sender IP (`--arp-ip-src`) is not in `ipfilter-net0` go to a chain that drops them. This is what keeps a server from answering ARP for a neighbour's or the gateway's address. The datacenter option `ebtables: 0` turns all of it off (its default is on).
- **IP**: outgoing packets whose source is not in the IP set are dropped (iptables/ip6tables). Proxmox adds the link-local address derived from the MAC itself (and refuses every other `fe80::/10` address).
- **Nothing filters at all** while the **datacenter firewall** is off (`cluster.fw` `enable`, off by default), or while `net0` lacks `firewall=1`. The panel never looked at either.
- **Router advertisements** sent by a VM are dropped unless the VM option `radv` is set.
- **DHCP server replies** sent by a VM (UDP 67 → 68, DHCPv6 547 → 546) **pass**: the `dhcp` option only adds rules for the client side, and the IP filter lets the VM's own address through as source. A customer could run a DHCP server for the whole bridge.
- **NDP**: the rules accept neighbour solicitations and advertisements by ICMPv6 type only. The **target address** inside a neighbour advertisement is never checked, so on a shared IPv6 subnet a server can still claim a neighbour's IPv6 address in an NDP advertisement sent from its own source address. Neither the iptables nor the nftables firewall (`proxmox-firewall`, a tech preview) offers a match for it through the API.
- Someone with access to Proxmox can turn any of this off for one VM, and adopted VMs (§3.8) never got it: adoption deliberately changes nothing.

## Decision

1. **The sync reads the datacenter firewall** (`GET /cluster/firewall/options`, `Sys.Audit` on `/`, which the token already needs) into `clusters.firewall_enabled` and `clusters.firewall_ebtables` (null until the first sync after the upgrade). While either is off:
   - the cluster page, the cluster list and the help say so;
   - **placement skips the cluster** (`NodePlacer`): automatic placement uses only clusters whose firewall works and fails with *"The Proxmox firewall is off on every cluster…"* when none is left; a node an administrator chose is refused with the reason. The billing API gets the usual `422`.
   - The panel does **not** turn the datacenter firewall on: that needs `Sys.Modify` (a dangerous privilege the permission check warns about), and enabling it with an input policy of `DROP` can cut the nodes off their own API and SSH. Administrators do it, and the page tells them to check the policy first.
2. **The VM firewall options are written in full** (`QemuResource::ANTI_SPOOFING_OPTIONS`): `enable`, `ipfilter`, `macfilter`, `radv: 0`, `ndp: 1`, `dhcp: 1`, so nothing depends on defaults. Two **outgoing DROP rules** (`DHCP_SERVER_RULES`, recognised by their comments, added once) drop DHCP and DHCPv6 server replies. Policies stay `ACCEPT` for servers the panel created (ADR 0015 §8); adopted VMs keep their own.
3. **The sync compares VM firewalls** (`FirewallChecker`): per sync of a cluster it looks at the 20 servers checked longest ago (`servers.firewall_checked_at`), each with five read calls (options, rules, config, IP sets, IP set entries). A difference is a drift of type `firewall_mismatch` (ADR 0005) listing what is wrong: firewall, IP or MAC filter off, router advertisements allowed, `net0` without the firewall flag, DHCP server rules missing, or an IP set that differs from the panel (addresses that are not the server's, addresses missing). A drift stays open until its server is looked at again. **Nothing is repaired automatically.**
4. **Administrators turn it on again**: *Enable anti-spoofing* on the server page (adopted servers, and servers with a firewall drift) and on the drift starts an `enable_anti_spoofing` task that writes the options and rules, makes the IP set exactly the server's addresses and turns on `net0`'s firewall flag (Proxmox applies it to a running VM). It confirms the addresses first, since a missing one stops working, runs nothing inside the guest and is audited as `server.anti_spoofing_enabled`. *Apply again* (ADR 0028 §3) also turns the firewall flag back on. Both read the VM firewall back at the end (`FirewallChecker::recheck()`): the firewall drift closes at once (resolution `restored`), or is updated with what is still wrong, without waiting for the next sync; the server page reloads its drifts while the task runs. The server list marks servers with an open firewall drift; the server page shows *Anti-spoofing on* once the firewall was found right, and offers the button to an adopted server only until then.

## Consequences

- ARP spoofing of a neighbour's or the gateway's IPv4 address, IP and MAC spoofing, rogue router advertisements and rogue DHCP servers are stopped on clusters whose datacenter firewall and ebtables are on — and new servers only go to such clusters.
- **NDP target spoofing on a shared IPv6 subnet remains possible.** The mitigation is the one `AGENTS.md` §3.7 already prefers: a routed `/64` per server, where there is no neighbour to impersonate. Pools that give several servers addresses from one bridged `/64` carry this risk; the admin guide says so.
- A cluster whose datacenter firewall is off gets no new servers. Existing installs see this after the first sync that reads the options; until then (`null`) placement is unchanged.
- The sync makes up to 100 more API calls per cluster every five minutes; with many servers a change is noticed within `servers / 20 × 5` minutes.
- Not verified on a real Proxmox yet: the ebtables ARP filter with `ipfilter-net0`, the DHCP DROP rules reaching server replies before the IP filter accepts them, `net0`'s firewall flag applied to a running VM, and the nftables firewall (`proxmox-firewall`), which Proxmox documents as doing the same with exceptions.
