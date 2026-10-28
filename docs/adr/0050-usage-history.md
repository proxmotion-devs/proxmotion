# 50. Usage history and graphs

- **Status:** Accepted
- **Date:** 2026-10-28

## Context

`usage:collect` samples every active or suspended server every 5 minutes (ADR 0039), but the samples are kept only 48 hours, for the usage limits' windows. The live cards of ADR 0036 show the last hour while an administrator watches a server page. Nobody can see how a server was used last week or last month: not administrators looking into a complaint, and not customers, whose billing modules show only the month's traffic total. Virtualizor and VirtFusion both show CPU, memory, network and disk graphs over days and months, and traffic per day.

Keeping the 5-minute samples longer would mean about 105,000 rows per server and year. Hosting providers need weeks and months, not 5-minute detail from months ago.

## Decision

1. **Samples carry their bytes.** `usage_samples` gains `net_in_bytes` and `net_out_bytes`: the bytes received and sent since the previous collection, the same figures `UsageCollector` adds to the traffic period (null for the first sample of a server). Traffic per hour and day is then the sum of samples, not a rate multiplied by a guessed interval.
2. **Rollups.** A new table `usage_rollups` holds one row per server, resolution (`hour` or `day`) and start: the number of samples, the averages of CPU and memory (per cent) and of the network and disk rates (bytes per second) over the samples that have a value, and the bytes received and sent. Hours and days start in the panel's time zone, like traffic periods.
3. **Written by the collection.** At the end of each `usage:collect` run, `UsageRollups` recomputes, for the servers just sampled, the current hour and the previous one (so the last samples of an hour are counted once the next has begun) from the samples, and the days those hours fall in from their hour rows (averages weighted by the number of samples). Rows are written with `upsert`, so a run can be repeated and two runs never add twice. Only the collection writes rollups.
4. **Kept** 35 days for hours and 400 days for days (`model:prune` daily). About 840 hour rows and 400 day rows per server.
5. **History by range** (`UsageHistory`): `day` = the samples of the last 24 hours (5 minutes), `week` = hour rows of the last 7 days, `month` = hour rows of the last 30 days, `year` = day rows of the last 365 days. Each point has its start time, the averages and the bytes. Traffic comes with it in buckets that suit the range: per hour for `day`, per day for `week` and `month`, per traffic period (ADR 0039 §3) for `year` (the last 12). Each bucket has bytes in, out and counted (Panel settings). Missing points are left out; clients break their lines at gaps.
6. **API:** `GET /servers/{id}/usage?range=day|week|month|year` (`servers:read`, default `day`), answering `{ data: { range, interval_seconds, from, to, points: [...], traffic: { interval, buckets: [...] }, current: <the server's traffic of this period> } }`. Additive; v1 stays compatible. Terminated servers answer like others (their history stays until pruned or the server row is deleted).
7. **Admin:** a *Usage* tab on the server page with the four ranges, line charts of CPU, memory, network and disk I/O and bars of the traffic, from `GET /admin/servers/{server}/usage?range=` (the same `UsageHistory`), drawn as SVG like the sparklines (no chart library).
8. **WHMCS client area:** a *Usage history* section with the same ranges (a link per range, `pmxrange` in the query), CPU and network as SVG line charts and traffic as bars, drawn by the module in PHP (no JavaScript, no external library), fetched on every view like the rest of the client area. The section is hidden when the panel answers `404` (a panel before this ADR).

## Consequences

- One more query and a few upserts per collection run; the 48-hour samples stay the only source.
- History begins with this change: samples older than the migration have no bytes, so the first rollups count traffic only from then on. Hours rolled up from fewer than 12 samples (a server created mid-hour, a cluster that did not answer) are averages of what was seen.
- CPU and memory are averages of instants (ADR 0039 consequences); peaks within an hour are not kept. 95th-percentile billing would need the maxima and is not done.
- Not done: WISECP's `metrics_usage` (its contract is unknown), throttling instead of suspending (ADR 0039), graphs in WISECP.
- Not verified on a real Proxmox, like everything built on ADR 0039's collection.
