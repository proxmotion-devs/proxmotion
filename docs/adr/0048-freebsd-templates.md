# 48. FreeBSD templates

- **Status:** Accepted
- **Date:** 2026-10-28

## Context

Hosting providers asked to offer FreeBSD servers. The panel knows two platforms (`OsPlatform`): Windows, and Linux for everything else. FreeBSD fits the Linux platform in what the panel relies on: the administrator account is `root`, cloud-init (from the FreeBSD packages) reads Proxmox's NoCloud CloudInit drive, the QEMU guest agent runs commands and writes files (`guest-exec`, `guest-file-*`), and its `guest-set-user-password` runs `pw usermod`. What does not fit are the tools the panel's default guest scripts (ADR 0009, 0010, 0011, 0012) and its checks from inside (ADR 0032 §6) use: `ip`, `/sys/class/net`, `findmnt`, `lsblk`, `growpart`, GNU `sed -i`, systemd units, GNU `df --output` and a `ping -W` in seconds (FreeBSD's waits milliseconds).

A new platform would touch every place that branches on Windows or Linux (scripts, verifier, passwords, apps, the API's `os_family`) for a system that behaves like Linux almost everywhere. Leaving FreeBSD on the Linux defaults would fail every address, disk and hostname script.

## Decision

1. **Platform Linux, distribution `freebsd`.** A FreeBSD template is an OS template whose operating system has the slug `freebsd` (`Distributions::FREEBSD`, FreeBSD's os-release ID) and the platform Linux. `Distributions` knows it (name FreeBSD, family `other`), and `TemplateNameGuess` suggests it for Proxmox templates with `freebsd` in their name.
2. **Default scripts of its own.** `GuestScripts::defaultScript()` takes the template's distribution and reads `resources/guest/freebsd/<event>.sh` for a Linux template of distribution `freebsd`, `resources/guest/<platform>/` otherwise. The *Scripts* page of such a template shows these as its defaults; overrides, drafts, trials and custom scripts (ADR 0010, 0023, 0043) work as for any template. The scripts keep the inputs and files of the Linux ones (`/etc/proxmotion/<event>.vars`, `/usr/local/sbin/proxmotion-<event>`):
   - `addresses.sh`: finds the interface by MAC with `ifconfig`, adds and removes aliases with `ifconfig … alias` / `-alias`, and for a primary that differs from cloud-init's (ADR 0011) points the default route through it with `route … -ifa` (the source of IPv4 connections; IPv6 sources follow RFC 6724), a host route to the gateway on the link for a routed `/32`, `%<interface>` for a link-local IPv6 gateway. An rc.d script `proxmotion_addresses` (`REQUIRE: NETWORKING`) runs it at every boot.
   - `disk.sh`: `camcontrol reprobe` for CAM disks, then FreeBSD's own `service growfs onestart` (GPT recovery, the last partition, UFS or ZFS), the one cloud-init runs on the first boot, with `growfs_swap_size=0` so growfs never adds a swap partition after root.
   - `hostname.sh`: `hostname`, `hostname=` in `/etc/rc.conf`, `preserve_hostname` in `/usr/local/etc/cloud/cloud.cfg.d/`, and the `127.0.0.1` / `::1` lines of `/etc/hosts` that cloud-init's FreeBSD template names the host on; an rc.d script `proxmotion_hostname` (`REQUIRE: cloudinit`) runs it after cloud-init rewrote `/etc/hosts` at boot.
3. **Portable checks from inside.** `ServerVerifier::LINUX_SCRIPT` reads the root file system's size with POSIX `df -P -k` (the fifth field from the end, since a device may contain spaces) and waits 2000 for `ping -W` on BSDs (milliseconds), 2 elsewhere (seconds). Linux results do not change.
4. **The template.** Built as `docs/guides/freebsd-templates.md` and the FreeBSD tab of the template guide say: OS type *Other*, UFS with root last and no swap partition, `net/cloud-init` and `qemu-guest-agent` from packages, `datasource_list: [NoCloud, None]` under `/usr/local/etc/cloud/`, `virtio_console_load` (the agent's device is not in GENERIC), `qemu_guest_agent_env` with `/usr/local/bin` in `PATH` (rc starts daemons without it, and the cloud-init wait would find no `cloud-init` and end at once), `growfs_swap_size=0`, a VirtIO RNG.
5. **Not offered.** Apps: the app runner needs bash and systemd; administrators must not tick FreeBSD versions for apps (nothing stops them, as for any system, ADR 0026). *Stop script* (ADR 0043 §7) cannot find a FreeBSD run's processes (`/proc/*/environ` is Linux's); the task ends, the script keeps running. The *Disk space* card stays empty with agents that lack FreeBSD's `guest-get-fsinfo` (added to QEMU in 2026).

## Consequences

- FreeBSD servers get every server operation of Linux servers except apps, without a new platform; the API reports them as `os_family` `other`.
- A FreeBSD template whose operating system has another slug (named, say, "FreeBSD 14") gets the Linux defaults and fails its scripts; the guide tells administrators to name the system FreeBSD.
- Nothing of this has run on a real FreeBSD guest yet (AGENTS.md, *Not yet verified*); the scripts were tried with stubbed `ifconfig`, `route` and `sysrc` only.
