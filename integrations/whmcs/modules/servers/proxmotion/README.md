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
| Change Package | Not supported yet: change the resources in Proxmotion |
| Client area | Status, power state, IP addresses with reverse DNS, start / shut down / reboot / force stop, browser console, root password reset, reinstall |
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

## Products

*System Settings → Products/Services → (product) → Module Settings*, module **Proxmotion**:

| Setting | Meaning |
|---|---|
| Package | The Proxmotion package (CPU, memory, disk, addresses, traffic, port speed), or **No package**: the resources come from configurable options only (see below) |
| Location | Where the server is placed, unless the `location` configurable option is chosen |
| OS template | The operating system, unless the `os_template` configurable option is chosen |
| Network model | Bridged or routed; default: the first model the package allows (bridged without a package) |

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
one address.

**Products without a package** (Package: *No package*) get all resources from
configurable options: `cpu_cores`, `memory_mib`/`memory_gib` and `disk_mib`/`disk_gib`
are required (the order fails with a readable error otherwise); addresses default to
one IPv4 address (`ipv4_count`, `ipv6_count` to change it); port speed and traffic are
unlimited unless given. `extra_*` options need a package and are refused here. The IP
addresses always come from Proxmotion's IP pools of the location. Changing configurable options of an existing service (upgrades) arrives
with package changes in a later version.

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

## Troubleshooting

- *Utilities → Logs → Module Log*: every API call is logged. The token and root
  passwords are masked.
- "This token does not have the … scope": create a token with all scopes.
- "Unauthenticated.": the token is wrong, revoked or expired.
- "This token may not be used from your address.": add the WHMCS server's address
  to the token in Proxmotion.
- 404 for a server: each billing system only sees its own servers; the service
  must have been created through this billing system's token (or assigned to the
  billing system in Proxmotion).
