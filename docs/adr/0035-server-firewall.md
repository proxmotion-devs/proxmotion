# 35. Server firewall

- **Status:** Accepted
- **Date:** 2026-10-21

## Context

Every server's VM firewall is on (ADR 0033), but its input policy is `ACCEPT`: it only stops spoofing, isolates servers where a pool asks for it, and keeps them away from the nodes (ADR 0034). Customers who want to close ports have to run a firewall inside their server. Panels like VirtFusion offer a per-server firewall with rule sets administrators prepare; `AGENTS.md` had "customer-managed firewall rules" in Phase 9, and the maintainers pulled it forward. App recipes already declare the ports they need (`firewall:`), recorded but not applied (ADR 0015 §8).

## Decision

### 1. What a server's firewall is

- The server's **rules always apply**, top to bottom. `servers.firewall_policy` says what happens to incoming traffic no rule matches: `accept` (the default: everything else comes in, as before) or `drop` (only what the rules allow comes in). So `drop 3389/tcp` closes RDP on a server that allows everything else, and a server that drops by default needs `accept` rules. Outgoing traffic is never filtered by it (policy `ACCEPT`); the Proxmox firewall is stateful, so answers to connections the server opens come back.
- A first version had an on/off switch instead and wrote no rules while it was off; an administrator's `drop 3389` then did nothing, which surprised everyone. Rules that only work after a second switch were the wrong model.
- **Inbound rules only** (`server_firewall_rules`, at most 50 per server, in order): action `accept` or `drop`, protocol `tcp`, `udp`, `icmp` or `any`, a port or range (`22`, `1000-2000`; not for icmp; `any` with a port is written as a tcp and a udp rule, `any` without one matches all traffic — before 2026-10-21 choosing `any` dropped the port and opened everything), a source (an address or CIDR, IPv4 or IPv6; empty = anywhere) and a comment of up to 60 printable characters.
- Customers (through the billing API and modules) and administrators (server page) manage them. Every change replaces the whole list and is an `apply_firewall` task in the queue, audited as `server.firewall_changed` with the rules before and after.
- The console never goes through the network, so a customer who locks themselves out can still log in there.

### 2. The rules in Proxmox

- The panel writes one block of rules on top of the VM firewall, top to bottom: the isolation rules (ADR 0034), the DHCP server rules (ADR 0033), the **app rules** (§4) and the **server's rules**. Every rule the panel writes has a comment starting with `Proxmotion`; when the block differs from what the panel wants in any way (missing, extra, changed, disabled, out of order), all of them are deleted and written again (Proxmox puts a rule added without `pos` at the top, so the last one first). Rules someone added in Proxmox themselves stay, below the block.
- The DHCP server rules are now written with the block instead of once on their own.
- A server rule becomes one Proxmox rule (`type in`, `ACCEPT` or `DROP`, `proto`, `dport` with `:` for ranges, `source`, comment `Proxmotion rule: <comment>`); `icmp` becomes `icmp` for an IPv4 source, `ipv6-icmp` for an IPv6 source, both without a source.
- The input policy is written with the block: `DROP` for a server that drops by default; `ACCEPT` otherwise for servers the panel created, and for adopted servers only when a firewall change sets it (otherwise their own policy stays, ADR 0033 §2). Adopted servers get the block once they have rules or a firewall change.

### 3. Default rule and rule sets

- **Rule sets** (*Admin → Firewall rule sets*, `firewall_rulesets`): a name, a description, a default policy (`drop` unless chosen otherwise) and up to 50 rules. Active rule sets are offered to customers (`GET /firewall-rulesets`) and administrators; applying one replaces the server's rules and default policy (`replace`) or appends its rules to the server's (`append`, up to 50; the server keeps its default policy).
- A package may name a rule set (`packages.firewall_ruleset_id`): its new servers start with its rules and default policy. The rule set form warns when a rule set that drops by default opens neither 22 nor 3389.
- **Default rule:** a server switched to `drop` without any rule that allows something gets one more rule, so nobody is locked out by accident: `22/tcp` (SSH) on a Linux template, `3389/tcp` (RDP) on a Windows template, both for a server without a template (adopted). It is an ordinary rule (`is_default` only remembers where it came from): it can be narrowed to a source or deleted. When a reinstall moves a server that drops by default to the other platform and its only rule is still that untouched default rule, it is swapped for the other one; otherwise the rules stay and the task says so.

### 4. App ports

The `firewall` ports of the server's apps (installations that are installing, installed, upgrading, uninstalling, or whose upgrade or uninstall failed) become `accept` rules (comment `Proxmotion app: <slug>`), with `{{ inputs.* }}` filled from the installation's inputs. They do not count towards the 50 rules and cannot be edited. They are written only while the server drops by default (otherwise they would change nothing), with every firewall write: the `install_app` task writes them once the installer has started, and a finished uninstall or upgrade queues an `apply_firewall` task (skipped while another task runs; the firewall check then reports it).

### 5. API v1

- `GET /servers/{id}/firewall` (`servers:read`): `default_policy`, `rules`, `app_rules`.
- `PUT /servers/{id}/firewall` (`servers:write`, idempotent): `default_policy` and the whole `rules` list → `202` with the task.
- `POST /servers/{id}/firewall/ruleset` (`servers:write`): `ruleset` and `mode` (`replace` or `append`) → `202`.
- `GET /firewall-rulesets` (`catalog:read`): the active rule sets with their default policy and rules.
- Only active and suspended servers; a running task is `409`.

### 6. Firewall check

`FirewallChecker` (ADR 0033 §3) also reports `firewall_rules_differ` (the block of panel rules differs from what the panel wants beyond the isolation and DHCP rules) and `firewall_policy_open` (the server drops by default but the input policy is not `DROP`). *Enable anti-spoofing* writes the whole block, the policy included.

### 7. Billing modules

The WHMCS client area gets a *Firewall* tab and WISECP a *Firewall* tool: allow or block everything else, the rules with add, delete and order, applying a rule set, and the app rules shown read-only.

## Consequences

- Customers can close single ports, or every port they do not use, without touching their server; servers that drop by default get their apps' ports opened automatically.
- A customer can lock out SSH or RDP; the default rule and the console make that recoverable.
- Every rule change rewrites the panel's block: up to about 60 rule deletions and as many additions per change.
- Not verified on a real Proxmox yet: `policy_in: DROP` with NDP and the guest's DHCP client still working (`ndp: 1`, `dhcp: 1`), `ipv6-icmp` as a protocol name, ranges in `dport` as `1000:2000`, rules with an IPv6 `source`, and that an app's ports work through the firewall.
