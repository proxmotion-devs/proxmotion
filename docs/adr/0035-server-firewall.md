# 35. Server firewall

- **Status:** Accepted
- **Date:** 2026-10-21

## Context

Every server's VM firewall is on (ADR 0033), but its input policy is `ACCEPT`: it only stops spoofing, isolates servers where a pool asks for it, and keeps them away from the nodes (ADR 0034). Customers who want to close ports have to run a firewall inside their server. Panels like VirtFusion offer a per-server firewall with rule sets administrators prepare; `AGENTS.md` had "customer-managed firewall rules" in Phase 9, and the maintainers pulled it forward. App recipes already declare the ports they need (`firewall:`), recorded but not applied (ADR 0015 §8).

## Decision

### 1. What a server's firewall is

- `servers.firewall_enabled` (default off). Off: the input policy is `ACCEPT`, as before. On: the input policy is `DROP` and only the server's rules let traffic in. Outgoing traffic is never filtered by it (policy `ACCEPT`); the Proxmox firewall is stateful, so answers to connections the server opens come back.
- **Inbound rules only** (`server_firewall_rules`, at most 50 per server, in order): action `accept` or `drop`, protocol `tcp`, `udp`, `icmp` or `any`, a port or range (`22`, `1000-2000`; tcp and udp only), a source (an address or CIDR, IPv4 or IPv6; empty = anywhere) and a comment of up to 60 printable characters.
- Customers (through the billing API and modules) and administrators (server page) manage them. Every change replaces the whole list and is an `apply_firewall` task in the queue, audited as `server.firewall_changed` with the rules before and after.
- The console never goes through the network, so a customer who locks themselves out can still log in there.

### 2. The rules in Proxmox

- The panel writes one block of rules on top of the VM firewall, top to bottom: the isolation rules (ADR 0034), the DHCP server rules (ADR 0033), the **app rules** (§4) and the **server's rules**. Every rule the panel writes has a comment starting with `Proxmotion`; when the block differs from what the panel wants in any way (missing, extra, changed, disabled, out of order), all of them are deleted and written again (Proxmox puts a rule added without `pos` at the top, so the last one first). Rules someone added in Proxmox themselves stay, below the block.
- The DHCP server rules are now written with the block instead of once on their own.
- A server rule becomes one Proxmox rule (`type in`, `ACCEPT` or `DROP`, `proto`, `dport` with `:` for ranges, `source`, comment `Proxmotion rule: <comment>`); `icmp` becomes `icmp` for an IPv4 source, `ipv6-icmp` for an IPv6 source, both without a source.
- The rules of a server whose firewall is off are kept in the panel but not written; turning it on writes them.
- The input policy is written with the block: `DROP` while the firewall is on; `ACCEPT` while it is off for servers the panel created, and for adopted servers only when a firewall change turns it off (otherwise their own policy stays, ADR 0033 §2).

### 3. Default rule and rule sets

- **Rule sets** (*Admin → Firewall rule sets*, `firewall_rulesets`): a name, a description and up to 50 rules. Active rule sets are offered to customers (`GET /firewall-rulesets`) and administrators; applying one replaces the server's rules or appends to them (up to 50) and turns the firewall on.
- A package may name a rule set (`packages.firewall_ruleset_id`): its new servers start with the firewall on and those rules. The rule set form warns when a rule set opens neither 22 nor 3389.
- **Default rule:** a server whose firewall is turned on (from off) with no rules gets one rule, so nobody is locked out by accident: `22/tcp` (SSH) on a Linux template, `3389/tcp` (RDP) on a Windows template, both for a server without a template (adopted). It is an ordinary rule (`is_default` only remembers where it came from): it can be narrowed to a source or deleted. When a reinstall moves the server to the other platform and its only rule is still that untouched default rule, it is swapped for the other one; otherwise the rules stay and the task says so.

### 4. App ports

The `firewall` ports of the server's apps (installations that are installing, installed, upgrading, uninstalling, or whose upgrade or uninstall failed) become `accept` rules (comment `Proxmotion app: <slug>`), with `{{ inputs.* }}` filled from the installation's inputs. They do not count towards the 50 rules and cannot be edited. They are written with every firewall write: the `install_app` task writes them before the installer starts, and a finished uninstall queues an `apply_firewall` task when the firewall is on (skipped while another task runs; the firewall check then reports it).

### 5. API v1

- `GET /servers/{id}/firewall` (`servers:read`): `enabled`, `rules`, `app_rules`.
- `PUT /servers/{id}/firewall` (`servers:write`, idempotent): `enabled` and the whole `rules` list → `202` with the task.
- `POST /servers/{id}/firewall/ruleset` (`servers:write`): `ruleset` and `mode` (`replace` or `append`) → `202`.
- `GET /firewall-rulesets` (`catalog:read`): the active rule sets with their rules.
- Only active and suspended servers; a running task is `409`.

### 6. Firewall check

`FirewallChecker` (ADR 0033 §3) also reports `firewall_rules_differ` (the block of panel rules differs from what the panel wants beyond the isolation and DHCP rules) and `firewall_policy_open` (the firewall is on but the input policy is not `DROP`). *Enable anti-spoofing* writes the whole block, the policy included.

### 7. Billing modules

The WHMCS client area gets a *Firewall* tab and WISECP a *Firewall* tool: on/off, the rules with add, edit, delete and order, applying a rule set, and the app rules shown read-only.

## Consequences

- Customers can close every port they do not use without touching their server; servers with apps get the apps' ports opened automatically.
- A customer can lock out SSH or RDP; the default rule and the console make that recoverable.
- Every rule change rewrites the panel's block: up to about 60 rule deletions and as many additions per change.
- Not verified on a real Proxmox yet: `policy_in: DROP` with NDP and the guest's DHCP client still working (`ndp: 1`, `dhcp: 1`), `ipv6-icmp` as a protocol name, ranges in `dport` as `1000:2000`, rules with an IPv6 `source`, and that an app's ports work through the firewall.
