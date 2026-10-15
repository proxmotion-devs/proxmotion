# GEMINI.md

All shared rules, architecture, commands and security principles for this project live in `AGENTS.md`:

@./AGENTS.md

## Gemini CLI specific notes

- Do not duplicate rules here; if a shared rule changes, update `AGENTS.md`.
- Read the relevant files before changing code and briefly summarize the planned change to the user.
- Never run shell commands that write to a real Proxmox server, a live WHMCS or a live Virtualizor; faked HTTP tests (`Http::fake()`) and the local Docker environment are enough.
- Do not read `.env` or print its contents; work only with `.env.example`.
- Laravel commands run inside `panel/` (`php artisan …`, `composer …`, `npm …`).
- Before finishing a task: `composer ci:check` inside `panel/` (plus `php artisan apps:validate` and `shellcheck` on changed scripts once those exist). PHP must be on `PATH` for the frontend commands too (Wayfinder runs `php artisan`).
- Reply to the user in their language (usually Turkish); everything written to the repo stays in English.
