# CLAUDE.md

All shared rules, architecture, commands and security principles for this project live in `AGENTS.md`:

@AGENTS.md

## Claude Code specific notes

- Do not duplicate rules here; if a shared rule changes, update `AGENTS.md`.
- For multi-step work (a new phase, pipeline, network model or API endpoint), plan first, then implement.
- Never run commands that write to a real Proxmox server, a live WHMCS or a live Virtualizor; faked HTTP tests (`Http::fake()`) and the local Docker environment are enough.
- Do not read `.env` or print its contents; work only with `.env.example`.
- Laravel commands run inside `panel/` (`php artisan …`, `composer …`, `npm …`).
- Before finishing a task: `composer ci:check` inside `panel/` plus `shellcheck` on changed scripts (CI runs both). Recipes and their `.proxapp` packages live in `proxmotion-recipes` (ADR 0041, 0042): check them there, with `php artisan apps:validate --path=<dir>`, `apps:pack --check` and `apps:check-versions`. PHP must be on `PATH` for the frontend commands too (Wayfinder runs `php artisan`).
- Reply to the user in their language (usually Turkish); everything written to the repo stays in English (except Turkish guide translations under `docs/guides/tr/`, see `AGENTS.md` §6).
