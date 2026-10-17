# Proxmotion module for WHMCS

A server module for **WHMCS 8 and 9** (PHP 7.2 or newer). WHMCS orders, suspends
and terminates VPS on Proxmotion, and customers manage their server in the WHMCS
client area. The module is a thin client of the Proxmotion billing API v1
(`docs/api/openapi.yaml` in the Proxmotion repository); Proxmotion does the work.

## What it does

| WHMCS | Proxmotion |
|---|---|
| Create | Orders the server (package, location, OS, hostname, password, SSH key, configurable options). Installation runs in Proxmotion; the client area shows the progress. Running Create again for a service that has a server changes nothing. |
| Suspend / Unsuspend | Stops / starts the server (the WHMCS suspension reason is passed on) |
| Terminate | Deletes the server and its disks; the addresses are released |
| Change Password | Sets the service password as the root password (at least 12 characters) |
| Change Package | Upgrades and downgrades: the new product's package and configurable options, with the same rules as ordering. CPU and memory take effect at the server's next restart (the client area says so) unless *Restart after upgrades* is on. The disk only grows. Additional IP addresses come from Proxmotion's pools; with fewer, the newest extra ones are released. |
| Client area | Status, power state, IP addresses with reverse DNS, hostname change (the service domain follows), start / shut down / reboot / force stop, browser console, root password reset, reinstall |
| Admin area | Server status and addresses on the service page, a link to the server in Proxmotion, power and password buttons |

The service password: when it has at least 12 characters, it becomes the root
password. Otherwise Proxmotion generates one and the module stores it on the
service. A password reset in the client area stores the new password there as well.

## Installation

1. Copy the `proxmotion` folder (this folder) to `modules/servers/` of your WHMCS.
2. In Proxmotion: *Admin → Billing systems* → add a billing system (e.g. "WHMCS"),
   then **Create token** with all scopes. Optionally limit it to your WHMCS server's
   IP address. Copy the token: it is shown once.
3. In WHMCS: *System Settings → Servers → Add New Server*:
   - **Module:** Proxmotion
   - **Hostname:** your panel's hostname, e.g. `panel.example.com`
   - **Password:** the API token (`pmx_…`)
   - **Secure:** checked (the panel must have a valid TLS certificate)
   - **Test Connection** must succeed.
4. Put the server into a server group and use that group in your products.
5. Optionally, [receive webhooks](#webhooks).

## Products

*System Settings → Products/Services → (product) → Module Settings*, module **Proxmotion**:

| Setting | Meaning |
|---|---|
| Package | The Proxmotion package (CPU, memory, disk, addresses, traffic, port speed), or **No package**: the resources come from configurable options only (see below) |
| Location | Where the server is placed, unless the `location` configurable option is chosen |
| OS template | The operating system, unless the `os_template` configurable option is chosen |
| Network model | Bridged or routed; default: the first model the package allows (bridged without a package) |
| Restart after upgrades | Restart the server right away when an upgrade or downgrade changes CPU or memory |

The lists are loaded from Proxmotion with the product's server.

### Configurable options

Configurable options are matched by their **option name** (the part before `|`, e.g.
`memory_gib|Memory (GB)`). Options with other names are ignored.

| Option name | Type | Value |
|---|---|---|
| `os_template` | Dropdown | Sub-option names `<OS template id>\|<name shown>`, e.g. `0199…\|Ubuntu 24.04` |
| `location` | Dropdown | Sub-option names `<location id>\|<name shown>` |
| `network_model` | Dropdown | `bridged\|Bridged`, `routed\|Routed` |
| `cpu_cores` | Quantity / dropdown | Number of vCPUs (replaces the package's value) |
| `memory_mib`, `memory_gib` | Quantity / dropdown | Memory (replaces the package's value) |
| `disk_mib`, `disk_gib` | Quantity / dropdown | Disk size |
| `ipv4_count`, `ipv6_count` | Quantity / dropdown | Number of addresses |
| `port_speed_mbps` | Quantity / dropdown | Port speed |
| `traffic_gb` | Quantity / dropdown | Monthly traffic in GB (10⁹ bytes) |
| `extra_<any of the above>` | Quantity / dropdown | **Added** to the package's value, e.g. `extra_memory_gib` with 2 adds 2 GB, `extra_ipv4_count` adds addresses |

The ids of OS templates and locations are listed by `GET /api/v1/os-templates` and
`GET /api/v1/locations`, and shown in the Proxmotion admin panel.

Resources stay within the limits of the Proxmotion admin form; a server needs at least
one address. The same options apply to upgrades and downgrades (*Upgrade/Downgrade
Options* in WHMCS): WHMCS then runs Change Package with the new values.

**Products without a package** (Package: *No package*) get all resources from
configurable options: `cpu_cores`, `memory_mib`/`memory_gib` and `disk_mib`/`disk_gib`
are required (the order fails with a readable error otherwise); addresses default to
one IPv4 address (`ipv4_count`, `ipv6_count` to change it); port speed and traffic are
unlimited unless given. `extra_*` options need a package and are refused here. The IP
addresses always come from Proxmotion's IP pools of the location.

### SSH key

Add a product custom field named `ssh_key|SSH public key` (text area, show on order
form). Its value is installed for root.

## Client area

The module replaces the product details overview. Texts are in the customer's
language when the module has it (`lang/english.php`, `lang/turkish.php`); other
languages fall back to English. Add a language by copying `lang/english.php`
to `lang/<WHMCS language name>.php` and translating it.

The **console** opens in a new tab through a link that is valid for 60 seconds and
works once; it opens only this server's console.

### Apps

Active servers show their installed apps and the apps that fit the server
(Proxmotion's App Marketplace: OpenVPN, WireGuard, Docker, …), each with a small form
for its settings. Installing takes a few minutes inside the server; the customer
reloads the page to see the progress, and can try a failed installation again. One app
installs at a time.

Outputs of installed apps (VPN client profiles, addresses) are fetched from Proxmotion
whenever the page is shown and files are downloaded through the module; nothing is
stored in WHMCS, and the module log masks them. They need a token with the
`apps:secrets` scope; without it, the apps are listed without outputs.

## Webhooks

Without webhooks, WHMCS only learns what happens to a server when it asks
(the client area, the admin service page). With them, Proxmotion tells WHMCS when
a task finishes, also for operations an administrator started in Proxmotion.

1. In Proxmotion: *Admin → Billing systems* → your billing system → **Add webhook**:
   - **URL:** `https://<your WHMCS>/modules/servers/proxmotion/webhook.php`
     (your WHMCS system URL followed by `modules/servers/proxmotion/webhook.php`)
   - **Events:** `server.provisioned`, `server.suspended`, `server.unsuspended`,
     `server.terminated`, `server.failed` (the others are only logged)
   - Copy the **signing secret** (`whsec_…`): it is shown once.
2. In WHMCS: *System Settings → Servers* → the Proxmotion server → **Access Hash**:
   paste the signing secret. Keep the API token in the **Password** field. If several
   WHMCS servers point to the same billing system, give each the same secret.
3. In Proxmotion, **Send test event**: the delivery log must show `200`, and
   *Utilities → Logs → Module Log* in WHMCS a `webhook ping` entry.

The receiver checks the signature, refuses requests signed more than 5 minutes
ago (keep the clocks of both servers in sync, e.g. with NTP) and ignores repeats of
an event. It acts only on services of the Proxmotion server whose secret signed the
event (the service id is the server's `external_id`):

| Event | In WHMCS |
|---|---|
| `server.provisioned` | A *Pending* service becomes *Active*; the domain follows the server's hostname |
| `server.suspended` / `server.unsuspended` | The service becomes *Suspended* / *Active*, if the server still is (events can arrive late, so the module asks Proxmotion first). A suspension WHMCS itself started changes nothing more. |
| `server.terminated` | The service is **not** changed: an email to administrators and an activity log entry say that it is still billed. Terminate or cancel it in WHMCS if it should not be. |
| `server.failed` | An email to administrators (WHMCS *account* notifications) and an activity log entry with the error |
| others | Logged only |

Every event is written to the module log (the secret masked). Status changes and
notifications also appear in the activity log. Seen event ids are kept for a week in
the table `mod_proxmotion_webhook_events`, which the receiver creates on its first
event. When Proxmotion cannot be reached to check a server's state, the receiver
answers `503` and Proxmotion sends the event again later.

## Troubleshooting

- *Utilities → Logs → Module Log*: every API call is logged. The token and root
  passwords are masked.
- "This token does not have the … scope": create a token with all scopes.
- "Unauthenticated.": the token is wrong, revoked or expired.
- "This token may not be used from your address.": add the WHMCS server's address
  to the token in Proxmotion.
- Webhook deliveries fail with `401`: the access hash of the WHMCS server is not the
  endpoint's current signing secret (it changes when it is replaced in Proxmotion).
  With `400` and "too old": the clocks of WHMCS and Proxmotion differ.
- 404 for a server: each billing system only sees its own servers; the service
  must have been created through this billing system's token (or assigned to the
  billing system in Proxmotion).
