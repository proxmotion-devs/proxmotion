# Contributing to Proxmotion

Thanks for your interest in contributing! This document explains how to get set up and what we expect from a pull request.

## Ground rules

- Be respectful — see the [Code of Conduct](CODE_OF_CONDUCT.md).
- Security issues go through [SECURITY.md](SECURITY.md), never public issues.
- For anything bigger than a small fix, open an issue first so we can agree on the approach.
- Significant design decisions are recorded as ADRs in [`docs/adr/`](docs/adr/).

## Development setup

See the [README](README.md#development). The architecture, layering rules and conventions are documented in [AGENTS.md](AGENTS.md) — they apply to human contributors too.

## Before opening a pull request

Run this inside `panel/` and make sure it passes — it runs formatting, lint, type checks, frontend tests, Pint, PHPStan and Pest, exactly like CI:

```bash
composer ci:check
```

And `shellcheck` on any shell script you changed.

## Pull request checklist

- [ ] Tests added or updated for the change
- [ ] New UI strings are translation keys with a Turkish entry in `lang/tr.json`
- [ ] Schema changes are in a **new** migration
- [ ] Public API changes are reflected in `docs/api/openapi.yaml` (and the WHMCS module, if affected)
- [ ] No secrets, real IPs, hostnames or customer data (use `192.0.2.0/24`, `2001:db8::/32`, `example.com` in fixtures)

## Commit messages

We use [Conventional Commits](https://www.conventionalcommits.org/), with a scope when it helps:

```
feat(provisioning): add routed IP network strategy
fix(whmcs): mask API token in module log
docs: describe NAT gateway design
```

## Licensing of contributions

By contributing, you agree that your contributions are licensed under the project's [AGPL-3.0 license](LICENSE). Any dependency you add must be AGPL-3.0-compatible.
