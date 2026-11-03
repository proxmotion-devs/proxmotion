# 56. Billing modules in their own repositories

- **Status:** Accepted (built 2026-11-03)
- **Date:** 2026-11-03
- **Builds on:** ADR 0007 (billing systems instead of a client panel), ADR 0008 (billing API v1), ADR 0014 (WISECP module), ADR 0041 (the recipes repository, moved the same way)

## Context

The WHMCS and WISECP modules lived in `integrations/whmcs` and `integrations/wisecp` of the panel repository. They are installed by copying them into the billing system, they target other PHP versions than the panel (7.2 and 8.2), they share no code with it and talk to it only through the public API v1. Their tests and PHPStan runs were part of the panel's `composer ci:check` through the panel's `vendor/`, so a module fix waited for the panel's checks, and providers had to take the module out of the panel's release. More billing modules are planned (Blesta, HostBill, Paymenter, FOSSBilling).

## Decision

1. **Each module moves to its own repository with its history** (split with `git subtree split`): `proxmotion-devs/proxmotion-WHMCS` (the contents of `integrations/whmcs`: `modules/servers/proxmotion/`, `phpstan/`, `tests/`) and `proxmotion-devs/proxmotion-WISECP` (the contents of `integrations/wisecp`: `coremio/modules/Servers/Proxmotion/`, `tests/`, `README.md`). `integrations/` is removed from the panel repository.
2. **Each repository checks itself:** its own `composer.json` with only development dependencies (PHPUnit, PHPStan, and php-parser for the WHMCS module's `Php72CompatibilityTest`), `phpunit.xml`, `composer ci:check`, CI on PHP 8.3 to 8.5 plus a `php -l` on the module's minimum PHP (7.2, 7.4 and 8.1 for WHMCS, 8.2 for WISECP), and Semgrep with `p/php`. The modules still have no run-time dependencies.
3. **The panel's checks no longer include the modules:** the `Whmcs` and `Wisecp` suites leave `panel/phpunit.xml`, their PHPStan configurations leave `composer types:check`, and Semgrep scans `panel` only.
4. **The API contract stays in the panel** (`docs/api/openapi.yaml`). A change to API v1 that a module uses is made in the panel first; the matching module changes are made in the module repositories, referring to the panel change. Each module repository has its own `AGENTS.md` with the rules of AGENTS.md §4.2 and §4.3 that concern its code.
5. Future billing modules start in repositories of their own, named `proxmotion-<billing system>`.

## Consequences

- Module fixes and releases no longer wait for the panel's CI or releases; providers download the module from its repository.
- The panel and a module can drift apart: nothing in the panel's CI notices when an API change breaks a module. API v1 never takes breaking changes (AGENTS.md §4.1), and each module's tests describe the answers it expects; a check of the modules against the panel's OpenAPI document is left for later.
- The local copy of WISECP used for reference (`WISECP-ORIGINAL-FILES/`, never committed, ADR 0014) is ignored by the WISECP repository instead of the panel's.
- The admin pages that explain the setup (*Billing systems*, *Help*) name the repositories instead of `integrations/` paths.
