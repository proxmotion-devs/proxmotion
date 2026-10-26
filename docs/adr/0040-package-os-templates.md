# 40. OS templates per package

## Context

Packages (plans) already name the placement groups their servers may use (ADR 0020) and the firewall rule set new servers start with (ADR 0035). They did not restrict the operating systems: every active OS template could be ordered or reinstalled with any package. Administrators want, for example, a Windows plan that only offers Windows templates, or a small plan without heavy systems.

Reinstalls and creates both pick an OS template, and the billing modules list templates for their forms. The billing API has no per-package or per-server template list today.

## Decision

- A package has an optional list of OS templates (`os_template_package`, pivot with `restrictOnDelete` on the template). **An empty list means any template**, as with placement groups.
- The list is checked where a template is chosen for a server **with a package**: creating a server (admin, API and the billing modules through the API), and reinstalling it (admin, API, the billing modules' reinstall forms, and app trial resets). Custom servers without a package may use any template.
- Changing a server's package does **not** check its template. The server keeps the template it has; the new package applies on the next create or reinstall. This avoids refusing a package change, a route that exists for upgrades and downgrades in billing systems.
- The restriction always applies to a package's servers, including inactive templates tried on test servers (ADR 0024 §8). Administrators who want to try an inactive template use a custom server.
- Deleting an OS template that a package names is refused, so a package never silently widens to every template.
- The billing API is extended additively: `os_templates` on each package (the uuids it names, empty for any); `GET /os-templates?package=` lists the active templates a package allows, and `?server=` the templates the server may be reinstalled with (its package, its cluster and, for a billing system's server, active ones only). The two filters cannot be combined. `GET /apps?os_template=&package=` lists no app for a pair the package does not allow.
- The admin create wizard lists only the templates the chosen package allows, and moves the selection to one it allows when the package changes.

## Consequences

- Placement, reinstalls and API creates share one check (`Package::allowsOsTemplate()`, `OsTemplate::scopeAllowedBy()`), so the admin, the API and the billing modules cannot disagree.
- A package that names only inactive templates offers its customers nothing; that is intended, as inactive templates are not offered to customers.
- Existing packages keep no list, so nothing changes for them until an administrator chooses templates.
- Pricing or quota effects are not part of this: the list only restricts which templates a package's servers may use.
- Not verified against a real WHMCS or WISECP: the modules only change which template list they request and send `?server=`; the forms show the server's allowed templates.
