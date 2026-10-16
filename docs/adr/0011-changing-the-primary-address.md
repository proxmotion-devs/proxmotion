# 11. Changing a server's primary address

- **Status:** Accepted
- **Date:** 2026-10-17

## Context

A server's primary address of each IP version is the one cloud-init configures (`ipconfig0`, `NetworkConfig`) and the one it sends traffic from. Until now it was implicitly the oldest assigned address. Providers need to change it: an additional address should become the primary (swap), or the primary should be replaced by another address from a pool (e.g. a blacklisted address, or a customer wanting a particular one).

The obvious way, changing `ipconfig0`, was measured on the test Proxmox (`CloudInitIdentityTest`, Debian 13, cloud-init 25.1.4, PVE 9.2.2): after the change and a restart, cloud-init got a **new instance id** and **regenerated the SSH host keys**. Customers would see a host key warning, and every per-instance module (users, passwords, keys) would run again.

## Decision

1. **Explicit primary.** `ip_addresses.is_primary` marks the primary of its version. A server's addresses are always ordered primary first, then by id (`Server::ipAddresses()`), so the first address of each version is the primary everywhere (cloud-init settings, anti-spoofing, guest scripts, surplus on downgrades, API). Without a flag the oldest address stays the primary, so existing servers need no migration of data. Releasing an address clears its flag.
2. **Two kinds of change, the address count never changes:**
   - an additional address of the server becomes the primary; the old primary becomes an additional address (swap);
   - a free address from a pool of the server's location and network model (the next free one, or one chosen by the administrator) becomes the primary; the old primary is **released** (and its PTR removed).

   Keeping the old address while taking a new one is an upgrade (one more address) followed by a swap, so billing always matches the address count.
3. **`ipconfig0` of an existing server is never changed.** The guest's network configuration written by cloud-init keeps the original address; the `addresses_changed` guest script (ADR 0010) gets the primary address, its gateway and the address cloud-init configured, and when they differ it adds the new primary, removes the original one unless it is still assigned, and sets the default route through the gateway with the new primary as source address. Its systemd unit applies this at every boot. A reinstall builds `ipconfig0` from the current primary, so cloud-init configures it natively again.
4. A change runs as a `change_primary_address` task under the location lock: firewall (new address allowed, released address removed only after that), release, guest script. Requested by administrators (server page) and the billing API (`POST /servers/{id}/primary-address`, `servers:write`); written to the audit log. The WHMCS client area does not offer it for now.

## Consequences

- No SSH host key change, no cloud-init re-run, no restart; the change applies within seconds when the guest agent answers. Without an agent, the step says so and the new primary works only after it is configured inside the server (or after a reinstall); the firewall already allows only the new set of addresses.
- Proxmox's own view (`ipconfig0` in the VM's cloud-init settings) shows the original address until a reinstall. Anything that changes the cloud-init settings later (an administrator editing them in Proxmox) makes cloud-init re-render the network with that original address, which the anti-spoofing filter blocks once it is released.
- The guest script now touches the primary address and the default route: a broken override can take the server offline. The default scripts are idempotent and only act when the primary differs from cloud-init's.
- The Windows default script does the same with `New-NetIPAddress`/`New-NetRoute`, unverified on a real Windows guest.
