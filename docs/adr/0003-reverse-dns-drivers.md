# 3. Reverse DNS through pluggable DNS drivers

- **Status:** Accepted
- **Date:** 2026-10-16

## Context

Customers can set reverse DNS (PTR records) for their public IPs (Phase 3, AGENTS.md 3.7). The panel stores the requested name, but something must publish it. Providers do this in very different ways:

- Their own authoritative DNS server (PowerDNS, BIND, Knot).
- A DNS host with an API (Cloudflare, deSEC, …).
- The upstream network provider, for IPs they lease (OVH, Hetzner and others each have their own API).
- By hand, from a list exported by the panel.

Hard-coding any one of these would tie the project to one company's setup (AGENTS.md 1, 6).

## Decision

1. **The panel database is the source of truth.** `IpAddress` gets a nullable `ptr` column and a `ptr_synced_at` timestamp. A pending change is visible in the UI until the driver confirms it.
2. **One driver interface** in `app/Domain/Networking/ReverseDns/`:
   ```php
   interface ReverseDnsDriver
   {
       public function set(IpAddress $address, string $hostname): void;
       public function remove(IpAddress $address): void;
       public function test(): void; // used by "Test connection" in admin
   }
   ```
3. **`DnsProvider` model** (admin-managed): name, driver key, encrypted credentials (`encrypted:array` cast, never sent to the browser), driver options. Each `IpPool` optionally points to one `DnsProvider` and a reverse zone (e.g. `2.0.192.in-addr.arpa`, `8.b.d.0.1.0.0.2.ip6.arpa`). A pool without a provider allows rDNS only as "record only".
4. **Bundled drivers:**
   - `none` — record only. Admins can export pending PTRs (CSV / BIND zone snippet) and mark them as applied.
   - `powerdns` — PowerDNS Authoritative HTTP API (open source, widely used by hosting providers).
   Further drivers (Cloudflare, provider-specific APIs) are added later as optional drivers. They are never required by the core.
5. **Writes run in the queue** (`ApplyReverseDns` job, retried with backoff). The HTTP request only validates, stores `ptr` and dispatches the job. Every change is written to `AuditLog` (`rdns.updated`).
6. **Validation** (AGENTS.md 7.7): the hostname must be a valid FQDN (max 253 characters, labels of LDH characters, no trailing wildcard). An optional per-pool setting requires forward-confirmed DNS (the name must resolve to the same IP) before the PTR is published. Customers may set PTRs only for addresses assigned to their own servers; for a routed IPv6 /64 only for addresses inside that /64. Changes are rate-limited per user.
7. **Lifecycle:** when an IP is released (terminate, IP removal), its PTR is removed through the driver and `ptr` is cleared, so the next customer does not inherit a name.
8. rDNS is available only for public models (bridged, routed, IPv6), never for NAT addresses.

## Consequences

- Phase 3 ships the interface, the `none` and `powerdns` drivers, and the admin/client UI. Providers with other setups can use "record only" until a driver exists.
- New tables/columns: `dns_providers`, `ip_pools.dns_provider_id`, `ip_pools.reverse_zone`, `ip_addresses.ptr`, `ip_addresses.ptr_synced_at`.
- Driver tests use `Http::fake()`; the PowerDNS driver is not verified against a real server until an integration test exists.
- Classless (RFC 2317) delegations are out of scope for the first version; the admin sets the zone name the driver must write to.
