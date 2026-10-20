# 28. Editing a server's addresses

- **Status:** Accepted
- **Date:** 2026-10-20

## Context

Until now an administrator could change a server's addresses in only two ways:

- *Change resources* (ADR 0009): a new IPv4/IPv6 count. More addresses are the next free ones of the first pool with room, fewer releases the **newest** additional ones. There is no way to pick which address goes.
- *Change primary address* (ADR 0011): swap with an additional address, or replace the primary with a free one. The count stays the same.

Administrators also asked for a way to apply the addresses again when the guest has lost them (the network configuration was edited by hand, a restore from an old backup, a guest script that failed), without changing anything in the panel.

The billing API and the billing modules are left out on purpose: billing systems sell a number of addresses, which `POST /servers/{id}/package` already covers.

## Decision

### 1. One change, one task

`Provisioning/AddressChanger::request()` takes an `AddressChange`:

- **add:** addresses to assign, each of an IP version, optionally from a given pool, either the next free address or a specific one (a free IPv6 address inside a pool's network may be new, as for the primary address), and optionally marked as the new primary of its version;
- **remove:** addresses of the server to release;
- **primary:** per IP version, the address that becomes the primary: one the server keeps, or a specific address added in the same change. An added address marked as the primary does the same without naming it, so the next free address can become the primary. Only one primary per version may be chosen.

Choosing an address the server keeps without removing the old primary is ADR 0011's swap (the old one stays as an additional address); choosing an added one and removing the old primary is its replacement.

Everything is decided under the location's lock inside one transaction, like provisioning. The Proxmox and guest work then runs as one `change_addresses` task. Nothing in the panel changes when a rule below fails.

Rules:

1. Only an *Active* server, and only while no other task runs on it.
2. New addresses come from active pools of the server's location and network model, on the **same bridge and VLAN** as the server's current addresses: the VM's `net0` is never changed. A pool given by the administrator must be one of these.
3. An address with its own virtual MAC (failover addresses) cannot be added: the MAC lives in `net0`, which needs a restart (same as ADR 0011).
4. The server keeps at least one address of each IP version it has. Removing every address of a version is a resource change (*Change resources*), not an edit.
5. The primary address of a version can only be removed when another one is chosen as the primary in the same change. When the primary of a version is not given, it stays as it is.
6. An empty change is refused.

The server's IPv4 and IPv6 counts are the addresses assigned to it, so they follow the change (the *Change resources* dialog shows the new numbers). The package keeps its own counts. When a billing system changes the package later, the counts go back to the package's, and fewer addresses still means the newest additional ones are released, including addresses added by hand.

### 2. The task

`AddressesPipeline` (before: `PrimaryAddressPipeline`, which did the same for `change_primary_address`) runs both task types:

1. anti-spoofing: the server's addresses go into `ipfilter-net0`, the removed ones come out;
2. the removed addresses are released (and their PTRs removed, ADR 0003), only now that they are out of the firewall;
3. the `addresses_changed` guest script (ADR 0009, 0010) configures the primary, the default route and the additional addresses inside the guest.

cloud-init's `ipconfig0` is never changed (ADR 0011). A re-run continues where the task stopped.

### 3. Apply again

`AddressChanger::reapply()` starts a `change_addresses` task that changes nothing in the panel: the firewall IP set is set to the server's addresses again and the guest script runs again. It is audited as `server.addresses_reapplied`. A change is audited as `server.addresses_changed` with the addresses before and after, the added and the removed ones.

### 4. Admin server page

- The header of *IP addresses* gets **Apply again** (with confirmation) and **Edit IP addresses**. The edit dialog lists the server's addresses with a primary choice per version and a *Remove* mark, and rows to add addresses (version, pool, next free or a specific address). It shows which addresses are released and that their reverse DNS records are removed.
- Every additional address gets a **Remove** button next to its rDNS button (with confirmation); it sends the same request with that one address.
- The edit dialog covers everything *Change primary address* did (swap, replace with the next free or a specific address), so that button and its admin route `POST /admin/servers/{server}/primary-address` are removed. The dialog's primary choice lists the addresses the server keeps and the rows being added (*Next free address (added)* or the specific address). `PrimaryAddressChanger` and the billing API's `POST /servers/{id}/primary-address` (ADR 0011) stay, as do `change_primary_address` tasks in the history.

## Consequences

- Administrators can choose exactly which address goes, and can repair a guest's network without a reinstall.
- An administrator can give a server more addresses than its package. The billing system does not know about them, and its next package change may release them.
- Not verified on a real Proxmox server: removing an address from `ipfilter-net0` (`DELETE .../firewall/ipset/ipfilter-net0/{cidr}`) and the guest script removing an address inside the guest are covered by `PrimaryAddressTest` and `GuestAddressesTest` for one template only.
