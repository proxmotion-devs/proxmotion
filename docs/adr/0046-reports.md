# 46. Reports

## Context

Administrators want answers the dashboard does not give: where they can still sell servers and what they need to buy, which servers use the most traffic, how the number of servers changes from month to month, and how often operations fail. The data is already in the panel's database: servers and their booked resources, nodes, storages and IP pools from the sync, monthly traffic and usage violations (ADR 0039), tasks and app installations. They also want the figures in a spreadsheet.

## Decision

1. **A *Reports* section** (`/admin/reports/{report}`, `ReportController`) with four reports, each a class in `app/Domain/Reports` implementing `Report` (filters, their choices, tables), listed in `Reports::PAGES` and `resources/js/lib/reports.ts`:
   - **Capacity:** per node the servers, vCPU cores and memory booked against the node's size after over-commit; per storage the disks booked against its size after over-commit and what Proxmox reports as used (a shared storage once per cluster, with the lowest over-commit of its nodes); per IP pool the addresses assigned, reserved and free (IPv6 pools have no size). Booked means every server that is not terminated, as placement counts it (`Server::occupying()`). Filter: location.
   - **Traffic and usage:** the traffic of one monthly period (ADR 0039; the current one and the eleven before it) per server, largest first, with the server's current quota, summed per location, package and billing system, and the usage violations that started in the period. Filters: period, location.
   - **Server inventory:** servers that are not terminated by status, location, package, OS template and billing system with their vCPU, memory and disk, and servers created by the panel, adopted and terminated in each of the last twelve calendar months. Filter: location.
   - **Operations:** tasks of the last 7, 30 (default), 90 or 365 days by type (succeeded, failed, queued or running, failure rate of finished tasks, median duration of successful ones), the failed tasks with who started them and their error, and app installations per app by their current status (trials left out). Filter: period.
2. **Computed on request, nothing stored.** Each table is a few grouped queries or one query over the period's rows, grouped in PHP; no report needs a new table, index or scheduled job.
3. **Tables carry raw values and typed columns** (`ReportColumn`: number, MiB, traffic bytes, per cent, seconds, ISO times, server status, task type, …). The page formats them; the CSV export writes them as they are, with the column keys as the header, so spreadsheets get numbers in fixed units. Labels are translated on the server.
4. **CSV export of every table** (`/admin/reports/{report}/{table}.csv`) with the filters of the page, every row. The page shows at most 100 rows of a table and says when the CSV has more. Text cells starting with `=`, `+`, `-`, `@`, a tab or a carriage return get a leading apostrophe, so a spreadsheet never reads a hostname, name or error message as a formula.
5. **Administrators only**, like every admin page (ADR 0007). Reports hold no secrets (hostnames, billing service ids, error messages of tasks), so opening or exporting one is not audited, like the rDNS export.

## Consequences

- History is limited by what the panel keeps: resolved usage violations are pruned after 90 days, so older periods show none; traffic periods and tasks are kept.
- Quotas in the traffic report are the servers' current ones, not those of the period.
- A report over many servers or a year of tasks loads its rows into memory once per request. That is fine for thousands of servers; a panel with far more would need aggregate tables.
- Graphs are not part of this decision; the tables are the basis for them later.
