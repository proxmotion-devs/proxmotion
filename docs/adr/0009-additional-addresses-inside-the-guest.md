# 9. Additional IP addresses inside the guest through the guest agent

- **Status:** Accepted; the delivery (script location, input files, overrides per OS template, Windows) is replaced by [ADR 0010](0010-guest-scripts-per-os-template.md)
- **Date:** 2026-10-16

## Context

Cloud-init configures one IPv4 and one IPv6 address per server (`ipconfig0`). Additional addresses, ordered with the server or added by an upgrade (`POST /servers/{id}/package`), are assigned in the panel and allowed by the anti-spoofing firewall, but the customer has to configure them inside the operating system. Changing `ipconfig0` to include them is not an option: Proxmox derives cloud-init's instance id from the configuration, so cloud-init would treat the next boot as a new instance (new SSH host keys, possibly reset users).

Every panel template ships the QEMU guest agent (ADR 0004), which the panel already uses for passwords, reinstall readiness and apps.

The distributions differ in how they configure networks (netplan with systemd-networkd on Ubuntu, ifupdown on Debian, NetworkManager on the RHEL family). Writing into those configurations means one code path per renderer, and merging with cloud-init's own files is easy to get wrong in a way that takes the server offline.

## Decision

1. The panel configures **additional addresses only** (not the primary ones from cloud-init) through the guest agent, after creation (when the server has any), after a reinstall, and after every resource change. It never edits the distribution's network configuration or cloud-init's files.
2. It writes two files with `file-write`: the list of addresses (`/etc/proxmotion/addresses`, one `address/prefix` per line, prefixes as in the API: routed IPv4 `/32`) and a fixed POSIX shell script from the panel's repository (`panel/resources/guest/proxmotion-addresses.sh`, installed as `/usr/local/sbin/proxmotion-addresses`). Then it runs the script with `exec`, passing the MAC address of `net0`. No customer input reaches the script; addresses come only from the panel's database.
3. The script finds the interface by its MAC address, adds the listed addresses with `ip addr replace`, removes the ones it added earlier that are no longer listed (it keeps its own state file, so it never removes addresses it did not add), and installs a systemd unit (`proxmotion-addresses.service`, oneshot, after `network-online.target`) that applies the list again at every boot. It is idempotent.
4. If the guest agent does not answer, the script fails (no `ip`, no systemd, Windows) or the customer removed the agent, the step is recorded with a message ("configure the additional addresses inside the server") and the operation still succeeds: the addresses are assigned and allowed either way.
5. Creation waits for the guest agent (as reinstall does, ADR 0004) only when the server has additional addresses.

## Consequences

- Additional addresses work out of the box on the panel's Linux templates, independent of the network renderer.
- A network manager that removes "foreign" addresses when it reconfigures a link (e.g. systemd-networkd on `networkctl reload`, NetworkManager on reapply) can drop them until the next boot or the next resource change. **This must be checked on the Ubuntu, Debian and RHEL-family templates** (integration test `GuestAddressesTest`); if it happens, the unit can be extended to re-run after the network service, without changing this decision.
- Customers who manage their network themselves can disable the unit; the panel then only rewrites the list file and re-runs the script on the next resource change.
- Windows guests keep configuring additional addresses themselves.
