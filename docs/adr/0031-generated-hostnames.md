# 31. Generated hostnames

- **Status:** Accepted
- **Date:** 2026-10-20

## Context

Every server needs a hostname: it becomes the Proxmox VM name, the cloud-init hostname and the name the panel shows. The admin form and `POST /servers` required one. When a WHMCS or WISECP customer left the domain empty (or typed something that is not a hostname), the modules sent `vps<service id>`, a name that means nothing outside the billing system and has no PTR.

Hosting providers usually name such servers after their address under their own domain, e.g. `ip-203-0-113-5.example.com`, and give the address the same PTR, so that the server has a name that resolves back and forth from the first boot. The domain differs between providers and often between data centres.

## Decision

### 1. A template per location

`locations.hostname_template` (nullable, at most 200 characters, stored lowercase), edited in the location dialog with a live example. Placeholders:

- `{ip}`: the server's primary IPv4 address with dashes (`203-0-113-5`); on a server without IPv4, its primary IPv6 address written out in full with dashes (`2001-0db8-0000-…-0005`, 39 characters, always a valid label);
- `{vmid}`: the VMID;
- `{location}`: the location's code.

A template must contain `{ip}` or `{vmid}`, so that each server gets its own name, may use no other placeholder, and must give a valid hostname with the longest values (an IPv6 `{ip}`, a nine-digit VMID). Without a template (the default), a hostname stays required in that location.

Per location rather than global or per IP pool: one setting per data centre is what providers asked for, a location already groups the pools a server's addresses come from, and no settings store had to be added.

### 2. Named after the addresses are assigned

The hostname becomes optional in the admin form and `POST /servers` (`NewServer::$hostname` is nullable). When it is empty and the location has no template, the request fails with a validation error under `hostname`. Otherwise `ServerCreator` names the server inside the same lock and transaction that assigns its addresses (`HostnameGenerator::generate()`), so the name is known before the create task starts and is in the `202` answer (`data.server.hostname`, added to the task's server object).

The name is not changed later: a new primary address (ADR 0011, 0028) keeps the hostname, as does a reinstall. Administrators and customers rename servers as before (ADR 0012).

### 2a. The VM is named after its hostname; notes and tags carry the address

*Revised 2026-10-20.* Proxmox's cloud-init has no hostname setting of its own: it hands the VM name to cloud-init as the hostname (`vps1` with `vps1.example.com` as FQDN; the part before the first dot), and Cloudbase-Init uses it for the Windows computer name. So a server named by its template gets the generated hostname as its Proxmox VM name, like any other server, and cloud-init and Cloudbase-Init set it at the first boot. No guest script runs and Windows needs no extra restart.

Administrators still find such servers in Proxmox by their address (`VmLabels`, for every server):

- the VM's notes (`description`) are `Managed by Proxmotion. Server <uuid>` followed by `Primary address: <IPv4>, <IPv6>`, the primary address of each version; the first part stays, since rebuilding from scratch deletes only a VM whose notes name its server (ADR 0032 §7);
- each primary address is a tag (`203.0.113.5`; an IPv6 address written out in full with dashes, since a tag has no colons), which Proxmox's tree and search show. Tags the VM already has stay; earlier address tags (anything that reads as an IPv4 address or a dashed IPv6 address) are replaced. Proxmox refuses tags that are not registered when the datacenter's *user tag access* is limited and the token lacks `Sys.Modify` on `/`: the configure step then notes *The address tags were not set: …* and the server is built without them.

Neither is part of the cloud-init data, so unlike the VM name (ADR 0012) they change with the addresses: a new primary or an address edit (ADR 0011, 0028) writes them again in its `addresses` step.

*First version (2026-10-20, superseded):* the VM was named after its primary address and the `hostname_changed` guest script set the hostname once the agent answered (Windows with one restart). That raced cloud-init on the first boot (a Debian 13 server renamed before cloud-init finished seemed to lose the name) and cost Windows a restart. Servers created then keep their address in `servers.vm_name` (null for every other server) and the pages still title them by it; their create and reinstall steps would still run the script (`CreateServerPipeline::nameGuest()`, step `guest_hostname`), but a reinstall clears `vm_name`, so the new VM is named after the hostname. Their running VMs are never renamed: a new VM name gives cloud-init a new instance (ADR 0012).

### 3. The PTR of the primary addresses

A generated name is also set as the PTR of the server's primary address of each version (`ReverseDns::setGenerated()`), published by the pool's driver like any other PTR (record-only pools keep it pending for export). Additional addresses get none. A hostname given in the order sets no PTR, as before.

The pool's forward confirmation (ADR 0003) is not applied: it keeps customers from claiming names they do not own, while the template is the administrators' own domain. Their DNS should resolve the generated names (e.g. a wildcard or generated records), otherwise forward-confirmed reverse DNS fails outside the panel.

A later hostname change moves PTRs that equal the old name, as ADR 0012 already does, so the generated PTR follows a rename.

### 4. Billing modules

`GET /locations` gains `generates_hostnames`. The WHMCS and WISECP modules send the service's hostname when it is valid. Otherwise they look up the order's location: with `generates_hostnames` they send no hostname, else `vps<service id>` as before. WHMCS then sets the service's domain to the name in the create answer (WISECP's `created()` already stores the server's hostname); the `server.provisioned` webhook sets it again as before. The extra `GET /locations` is only made when the order has no valid hostname.

## Consequences

- API v1 stays compatible: `hostname` becomes optional, `generates_hostnames` and the task's `server.hostname` are new fields.
- Locations without a template behave exactly as before.
- Generated names are only as unique as the template: `{ip}` names are unique among live servers, `{vmid}` only within a cluster or standalone node, since VMIDs are per cluster; prefer `{ip}` for a location with several clusters.
- Cloudbase-Init shortens a Windows computer name to 15 characters (NetBIOS); a template for Windows servers should give a short first label.
- Not verified against real systems: the hostname cloud-init and Cloudbase-Init derive from a generated VM name, the notes and address tags (also with limited user tag access, and Proxmox's search finding a VM by its tag), publishing the generated PTRs through PowerDNS, and the modules in a real WHMCS and WISECP.
