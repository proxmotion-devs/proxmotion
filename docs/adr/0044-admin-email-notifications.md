# 44. Email notifications for administrators

- **Status:** Accepted (built 2026-10-27)
- **Date:** 2026-10-27

## Context

The panel already tells billing systems what happened through webhooks (ADR 0013), but administrators only find out by opening the panel. Some events need someone to act: a failed provisioning, a cluster the panel can no longer reach, an app script that failed, a server over its quota, drift between the panel and Proxmox (Phase 3 left drift notifications open), webhooks a billing system never received, or a new administrator account. The panel had no mail code at all. Laravel's mail settings (`MAIL_*`) were already in `.env`, and its `User` model was already `Notifiable`.

## Decision

1. **Only administrators get email.** Customers live in the billing system (ADR 0007), which sends them its own email. The panel never writes to a customer address.
2. **Laravel notifications, no new dependency.** `App\Domain\Notifications\AdminNotifier` is the only place that sends them. Each event has a method there, and every method ends in one `AdminAlert` notification (mail channel, Markdown mail). It is queued (`ShouldQueue`, `afterCommit`, 3 tries), so no request, pipeline or scheduled command waits on SMTP. A notification never changes the outcome of the operation that caused it: `AdminNotifier` reports its own errors and returns, like the webhook calls next to it.
3. **SMTP stays in `.env`.** The mail transport (`MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME`, `MAIL_FROM_*`) remains a deployment setting, as ADR 0038 §4 decided. Password resets and email verification go through the same mailer. If a signed-in session could point it at another server, it could read other administrators' reset links. *Panel settings → Set in .env only* shows the mailer and the sender read-only, and warns when the mailer only logs (`log`, `array`). The password and username are never sent to the browser.
4. **Events** (`App\Enums\NotificationEvent`):

   | Event | When | Repeats |
   |---|---|---|
   | `task_failed` | a server task ends as failed (pipeline error, job failure, *Mark as failed*) | each task |
   | `app_failed` | an app installation, uninstall, upgrade or action fails (trials of drafts excluded) | each attempt or run |
   | `usage_exceeded` | a server goes over its traffic quota or a usage limit (ADR 0039) | each violation |
   | `drift_detected` | a cluster sync finds drift that was not open before | once per cluster per throttle window |
   | `cluster_unreachable` | a sync fails after the previous one succeeded | once per cluster per throttle window |
   | `webhook_failed` | a webhook delivery gives up after its last attempt | once per endpoint per throttle window |
   | `admin_created` | an administrator account is created | each account |

   Events that a scheduled command can raise again and again are throttled with `Cache::add()` per event and subject for *Repeat notifications after* minutes (default 60).
5. **Who receives them.** Each administrator ticks the events they want under *Settings → Notifications* (`users.notification_events`, JSON). Until they do, they get `task_failed`, `cluster_unreachable`, `webhook_failed` and `admin_created`. Only addresses that have been verified receive mail. *Panel settings → Notifications* can add up to 10 further addresses (e.g. a team mailbox), and these receive every event. Each administrator's mail is written in their own language (`User` implements `HasLocalePreference`). The further addresses get the panel's default language.
6. **Off until turned on.** `notifications_enabled` (`PROXMOTION_NOTIFICATIONS`, default `false`), `notification_recipients` and `notification_throttle_minutes` are Panel settings (ADR 0038) on their own page. *Send a test email* queues one test mail to the signed-in administrator through the configured mailer, also while notifications are off. It is limited to 3 per minute and audited as `notifications.test_sent`. Changing the settings is audited like every page (`settings.updated`).
7. **What a mail contains.** Mails give a short subject, what happened and a button to the panel page that shows it (the server, the cluster, the drift report, the billing systems, the administrators). They never contain secrets: no root password, app output, token, webhook secret or customer data beyond the server's hostname and the billing system's name. Task and script errors are shortened to 500 characters.
8. **The default queue.** Notifications run on the `default` queue, like the other short jobs. A separate queue would need every worker configuration (`composer dev`, Docker, the native installer, Horizon later) to list it, or mails would never leave. Each mail is one SMTP connection with Laravel's timeout, so provisioning is not held up. A dedicated queue can follow once Horizon is set up.

## Consequences

- Administrators learn about failures without watching the panel, and choose for themselves which events they get.
- A broken SMTP setup only fails the mail jobs (visible in `failed_jobs` and the log) and never the operations that caused them. *Send a test email* is how administrators check the setup.
- There is no in-panel notification list. The notification is written so that a `database` channel can be added later without new event code.
- Mail delivery has not been tested against a real SMTP server. The tests use `Notification::fake()` and render the mails.
