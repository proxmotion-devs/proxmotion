# 19. Paid apps and entitlements

- **Status:** Accepted
- **Date:** 2026-10-18
- **Builds on:** ADR 0007 (billing systems own customers), ADR 0008 (billing API), ADR 0015 (App Marketplace), ADR 0016 §5 (apps chosen with a new server or a reinstall).

## Context

A recipe says `billing: free` or `billing: paid` (AGENTS.md 3.5). Paid apps cost the provider money (a commercial control panel, a licence bundle, support) or are simply sold, so a customer may install one only after paying for it. Proxmotion has no customers or prices (ADR 0007): the billing system sells, and the panel must only know whether a server may get a paid app. Until now the panel refused every paid app ("Paid apps cannot be installed yet").

The billing system sells a paid app in two ways: as a configurable option of the VPS order (the app comes with the server, ADR 0016 §5), or as a product addon bought later for an existing service.

## Decision

1. **An entitlement** is the right to install one paid app on one server. It is stored in `app_entitlements`: the server, the app, its source (`order`, `api` or `admin`), an optional `reference` from the billing system (e.g. the WHMCS addon id, at most 191 characters), who granted it (API token or administrator) and when it was revoked. A server has at most one active (not revoked) entitlement per app; granting again returns the active one. Revoked entitlements stay as history.
2. **An order with a paid app grants it.** When `POST /servers` (or the admin create form) chooses a paid app, the billing system has charged for it with the order, so the panel records an entitlement with source `order` in the same transaction as the server. Nothing else grants rights implicitly.
3. **Installing later needs an active entitlement**, granted by the billing system through `POST /servers/{id}/app-entitlements` (source `api`, typically when an addon is activated) or by an administrator (source `admin`). Without it the install answers `422`. A retry of a failed paid installation and a reinstall with a paid app need one too; the entitlement from the order stays active across reinstalls.
4. **Revoking** (`DELETE /servers/{id}/app-entitlements/{app}`, an administrator, or addon cancellation in the billing module) only stops new installs, retries and reinstalls with the app. It never uninstalls an installed app, and uninstall and actions of an installed paid app keep working: removing software from a customer's server is the customer's or the administrator's decision, not a billing event.
5. **The catalog lists paid apps.** `GET /apps` shows them with `billing: paid` like free ones. With `server`, each app also says whether that server is `entitled`; a paid app without an entitlement is listed with `entitled: false`, so the module can offer to buy it instead of hiding it. The order catalog (`mode=order`) lists paid apps without the field: ordering grants the right.
6. `license: external` (cPanel, Plesk) is unchanged: the panel only installs, the licence is a separate product of the billing system, and the module shows a notice. A recipe may be both `paid` and `external`.
7. Granting and revoking are audited (`app.entitlement_granted`, `app.entitlement_revoked`) and scoped to the token's billing system like every server endpoint. They send no webhooks: the billing system made the change.

## Consequences

- The panel never stores prices or payment state; a billing system that forgets to revoke leaves the right in place, and a billing system that never grants one cannot install paid apps later (but can still order them with the server).
- One more resource per billing module: WHMCS and WISECP grant on addon activation and revoke on cancellation or termination of the addon; their client areas show "Buy" for paid apps that are not `entitled`.
- Terminating a server keeps its entitlements as history; they do not move to another server.
- No per-customer or per-period entitlements: a paid app is bought for one server. Subscriptions that end are the billing system's revocation.
