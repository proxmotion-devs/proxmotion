# Proxmotion module for WISECP

A server module for **WISECP 5** (PHP 8.2 or newer). WISECP orders, suspends and
terminates VPS on Proxmotion, and customers manage their server with WISECP's own
server tools. The module is a thin client of the Proxmotion billing API v1
(`docs/api/openapi.yaml` in the Proxmotion repository); Proxmotion does the work.
Design: [ADR 0014](../../docs/adr/0014-wisecp-module.md).

> **Not run in a real WISECP yet.** It was written against WISECP 5.0-beta.3 and
> tested with WISECP and the Proxmotion API faked. Please report what does not work.

## What it does

| WISECP | Proxmotion |
|---|---|
| Create | Orders the server (package, location, OS, hostname, password, SSH key, resources, add-ons). Installation runs in Proxmotion; the service page shows the progress. Running Create again for a service that has a server changes nothing. |
| Suspend / Unsuspend | Stops / starts the server (the suspension reason is passed on) |
| Cancel (terminate) | Deletes the server and its disks; the addresses are released |
| Upgrade / downgrade | The new product's package and resources, with the same rules as ordering. CPU and memory take effect at the server's next restart unless *Restart after upgrades* is on. The disk only grows. |
| Change password | Sets the root password (12 to 128 characters) |
| Service page | Hostname, IP address, OS, location, status and resources; tools: start, shut down, restart, force stop, reset root password, reinstall OS, VNC console, hostname change, reverse DNS, apps |

The root password: the customer's order answer or the service's password when it has
12 to 128 characters, otherwise Proxmotion generates one and the module stores it on the
service. After a reinstall, Proxmotion's new password appears on the service the next
time its page is opened.

## Installation

1. Copy the `coremio` folder of this directory into your WISECP root (it adds
   `coremio/modules/Servers/Proxmotion`).
2. In Proxmotion: *Admin → Billing systems* → add a billing system (e.g. "WISECP"),
   then **Create token** with all scopes. Optionally limit it to your WISECP server's
   IP address. Copy the token: it is shown once.
3. In WISECP: *Products → Hosting & Servers → Server Settings → Add New Server*,
   tab **Virtualization**, module **Proxmotion**:
   - **Panel address:** your panel's hostname, e.g. `panel.example.com`, with the
     https switch on and port 443 (the panel must have a valid TLS certificate)
   - **Username (not used):** anything, e.g. `proxmotion` (WISECP requires the field)
   - **API token:** the token (`pmx_…`), in WISECP's password field
   - **Test Connection** must succeed.
4. Use the server (or a server group with it) in your products.
5. Optionally, [receive webhooks](#webhooks).

## Products

On the product's **Module** tab, with module **Proxmotion**:

| Setting | Meaning |
|---|---|
| Package | The Proxmotion package (CPU, memory, disk, addresses, traffic, port speed), or **No package**: the resources come from the fields below |
| Location, OS template | Where the server is placed and what it runs, unless the customer chooses (order answers) |
| Network model | Bridged or routed; default: the first model the package allows (bridged without a package) |
| Restart after upgrades | Restart the server right away when an upgrade or downgrade changes CPU or memory |
| vCPU cores, Memory (GB), Disk (GB), IPv4/IPv6 addresses, Port speed (Mbps), Monthly traffic (GB) | Replace the package's value; empty: the package's. Without a package vCPU, memory and disk are required. |

The lists are loaded from Proxmotion through the product's server. Without a connection,
the ids can be typed in (`GET /api/v1/locations` and `GET /api/v1/os-templates` list them).

### Order answers

Product requirements (questions on the order form) can be mapped to the module:

| Module value | Answer |
|---|---|
| `os_template` | Selection whose option values are OS template ids from Proxmotion |
| `location` | Selection whose option values are location ids |
| `hostname` | Text: the server's hostname (otherwise the service's, or `vps<service id>`) |
| `ssh_key` | Text area: an SSH public key installed for root |
| `root_password` | Password: 12 to 128 characters |

### Add-ons

Add-on options can carry module values that are **added** to the package's (or the
product's) value: `extra_cpu_cores`, `extra_memory_gib`, `extra_disk_gib`,
`extra_ipv4_count`, `extra_ipv6_count`, `extra_port_speed_mbps`, `extra_traffic_gb`.
They need a package or the matching product field; otherwise the order fails with a
readable error. When an add-on changes, upgrade the service so Proxmotion gets the
new values.

## Client area

WISECP shows its own server page. Tools are hidden while Proxmotion is installing,
reinstalling or terminating the server, and when it is not active.

The **VNC console** opens Proxmotion's console in a new tab through a link that is
valid for 60 seconds and works once; it opens only this server's console.

### Apps

The **Apps** tool (the module's own page, `pages/tools/apps.php`: WISECP has no app
tool) shows the server's installed apps and the apps that fit it (Proxmotion's App
Marketplace: OpenVPN, WireGuard, Docker, …), each with a small form for its settings.
Installing takes a few minutes inside the server; the page shows the progress and
reloads itself while an app installs, and a failed installation can be tried again.
One app installs at a time.

Outputs of installed apps (VPN client profiles, addresses) are fetched from Proxmotion
whenever the page is shown, and files when the customer downloads them; nothing is
stored in WISECP. The module log masks app settings and outputs, and the settings are
sent in a field WISECP masks in the service history. Outputs need a token with the
`apps:secrets` scope; without it, the apps are listed without outputs.

Installed apps also offer their **actions** (e.g. adding, revoking or listing VPN
clients), each with a small form, and **Uninstall** when the app has an uninstall
script (asked to confirm). The latest runs show with their status, error and outputs (a
new client's profile is downloaded from the run), fetched on demand like the install
outputs and masked in the module log. One action runs at a time per app; the page shows
its result once Proxmotion has read it (about a minute).

## Webhooks

Without webhooks, WISECP only learns what happens to a server when someone opens it.
With them, Proxmotion tells WISECP when a task finishes, also for operations an
administrator started in Proxmotion.

1. In Proxmotion: *Admin → Billing systems* → your billing system → **Add webhook**:
   - **URL:** `https://<your WISECP>/api/v1/proxmotion/webhook`
   - **Events:** `server.provisioned`, `server.suspended`, `server.unsuspended`,
     `server.terminated`, `server.failed` (the others are only logged)
   - Copy the **signing secret** (`whsec_…`): it is shown once.
2. In WISECP: the Proxmotion server → **Webhook signing secret**: paste it. If several
   WISECP servers point to the same billing system, give each the same secret.
3. In Proxmotion, **Send test event**: the delivery log must show `200`, and WISECP's
   module log a `webhook ping` entry.

The receiver checks the signature, refuses requests signed more than 5 minutes ago
(keep the clocks in sync, e.g. with NTP) and ignores repeats of an event. It acts only
on services of the Proxmotion server whose secret signed the event (the service id is
the server's `external_id`):

| Event | In WISECP |
|---|---|
| `server.provisioned` | The service gets the server's hostname and addresses; a service still *waiting* or *in process* becomes *active* |
| `server.suspended` / `server.unsuspended` | The service becomes *suspended* / *active*, if the server still is (events can arrive late, so the module asks Proxmotion first) |
| `server.terminated` | The service is **not** changed: administrators get a notice and the service history says it is still billed. Cancel it in WISECP if it should not be. |
| `server.failed` | A notice to administrators (WISECP's *failed service activation* message, with the error) and a service history line |
| others | Logged only |

Every accepted event is written to the module log (the secret masked); refused requests
are logged without their body. Seen event ids are kept for a week in the table
`proxmotion_webhook_events`, created on the first event. When Proxmotion cannot be
reached to check a server's state, the receiver answers `503` and Proxmotion sends the
event again later.

## Troubleshooting

- WISECP's module log: every API call is logged. The token, root passwords and app
  settings and outputs are masked.
- No **Apps** tool on the service page: the server is not active, or the tool was
  disabled for the server or service in WISECP.
- "This token does not have the … scope": create a token with all scopes.
- "Unauthenticated.": the token is wrong, revoked or expired.
- "This token may not be used from your address.": add the WISECP server's address
  to the token in Proxmotion.
- Webhook deliveries fail with `401`: the server's signing secret is not the endpoint's
  current one. With `400` and "too old": the clocks differ. With `404`: WISECP did not
  route `/api/v1/proxmotion/webhook` to the module — please report it (ADR 0014).
- 404 for a server: each billing system only sees its own servers; the service must
  have been created through this billing system's token.
