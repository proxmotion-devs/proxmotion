# 1. Record architecture decisions

- **Status:** Accepted
- **Date:** 2026-10-15

## Context

Proxmotion has several design choices that are not obvious from the code alone (NAT gateway design, cloud-init snippet delivery, placement scoring, …). Contributors need to know what was decided and why.

## Decision

We record significant architecture decisions as Architecture Decision Records in `docs/adr/`, numbered sequentially (`NNNN-short-title.md`), using this format: Status, Date, Context, Decision, Consequences.

The baseline decisions already made are documented in [AGENTS.md](../../AGENTS.md):

- Laravel 13 (Vue starter kit) + Vue 3 / Inertia 3 / TypeScript + Tailwind 4, Vite+, MariaDB, Redis
- AGPL-3.0 license; English for code and docs, English + Turkish UI
- Proxmox API only (no SSH to hypervisors); QEMU Guest Agent for in-VM operations
- Docker Compose as the primary deployment path, plus a native installer

## Consequences

Decisions that change or extend the architecture require a new ADR. Superseded ADRs are kept and marked `Superseded by NNNN`.
