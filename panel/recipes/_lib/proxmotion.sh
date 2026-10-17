#!/bin/bash
# Helpers for app recipes (docs/adr/0015). The panel writes this file next to
# a recipe's install.sh in /var/lib/proxmotion/apps/<installation>/ and the
# runner (run.sh) exports PROXMOTION_APP_DIR. Scripts source it with:
#
#   . "$PROXMOTION_APP_DIR/proxmotion.sh"
#
# Inputs are only ever read from inputs.json (pm_input), never interpolated
# into a script by the panel. Progress goes to status.json, which the panel
# reads through the guest agent; outputs go to outputs.json and outputs/.

: "${PROXMOTION_APP_DIR:?PROXMOTION_APP_DIR is not set}"

# A JSON string of $1 (control characters dropped).
pm_json_string() {
    local s
    s=$(printf '%s' "$1" | tr -d '\000-\010\013\014\016-\037')
    s=${s//\\/\\\\}
    s=${s//\"/\\\"}
    s=${s//$'\n'/ }
    s=${s//$'\r'/}
    s=${s//$'\t'/ }
    printf '"%s"' "$s"
}

# pm_status STATUS PROGRESS STEP [MESSAGE]: replace status.json atomically.
pm_status() {
    local tmp="$PROXMOTION_APP_DIR/status.json.tmp"
    printf '{"status":%s,"progress":%d,"step":%s,"message":%s,"updated_at":%d}\n' \
        "$(pm_json_string "$1")" "$2" "$(pm_json_string "$3")" "$(pm_json_string "${4:-}")" "$(date +%s)" >"$tmp"
    mv -f "$tmp" "$PROXMOTION_APP_DIR/status.json"
}

# pm_step PROGRESS MESSAGE: report the step the script is starting (0-99).
pm_step() {
    printf '%d\n%s\n' "$1" "$2" >"$PROXMOTION_APP_DIR/state"
    pm_status installing "$1" "$2"
    echo "==> $2"
}

# pm_current progress|step: the last reported step.
pm_current() {
    if [ ! -f "$PROXMOTION_APP_DIR/state" ]; then
        [ "$1" = progress ] && echo 0
        return 0
    fi

    if [ "$1" = progress ]; then
        sed -n 1p "$PROXMOTION_APP_DIR/state"
    else
        sed -n 2p "$PROXMOTION_APP_DIR/state"
    fi
}

# pm_fail MESSAGE: stop the install with a message shown to the customer.
pm_fail() {
    printf '%s\n' "$*" >"$PROXMOTION_APP_DIR/failure"
    echo "ERROR: $*" >&2
    exit 1
}

# pm_input KEY: the validated value of an input (empty when not given).
pm_input() {
    jq -r --arg key "$1" 'if has($key) and .[$key] != null then .[$key] | tostring else empty end' "$PROXMOTION_APP_DIR/inputs.json"
}

# pm_server KEY: hostname, primary_ipv4 or primary_ipv6 of the server (empty when it has none).
pm_server() {
    jq -r --arg key "$1" '.[$key] // empty' "$PROXMOTION_APP_DIR/server.json"
}

# pm_output KEY VALUE: a text or URL output.
pm_output() {
    local tmp="$PROXMOTION_APP_DIR/outputs.json.tmp"
    jq --arg key "$1" --arg value "$2" '.[$key] = $value' "$PROXMOTION_APP_DIR/outputs.json" >"$tmp"
    mv -f "$tmp" "$PROXMOTION_APP_DIR/outputs.json"
}

# pm_output_file FILENAME SOURCE: a file output (the recipe declares FILENAME).
pm_output_file() {
    install -m 600 "$2" "$PROXMOTION_APP_DIR/outputs/$1"
}

# pm_apt_update / pm_apt_install PACKAGE...: apt without prompts, waiting for other apt runs.
pm_apt_update() {
    apt-get -o DPkg::Lock::Timeout=600 update -q
}

pm_apt_install() {
    DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=600 install -y -q --no-install-recommends "$@"
}

# pm_pkg_install PACKAGE...: install with whichever of apt and dnf the system
# has (recipes requiring "apt|dnf", docs/adr/0016); package names must exist
# under the same name in both.
pm_pkg_install() {
    if command -v apt-get >/dev/null 2>&1; then
        pm_apt_update && pm_apt_install "$@"
    elif command -v dnf >/dev/null 2>&1; then
        dnf install -y -q "$@"
    else
        pm_fail "Neither apt nor dnf is available."
    fi
}

# pm_default_interface: the interface of the IPv4 default route.
pm_default_interface() {
    ip -4 route show default | awk '{ for (i = 1; i < NF; i++) if ($i == "dev") { print $(i + 1); exit } }'
}

# pm_public_address: the address clients connect to (primary IPv4, else IPv6, else the hostname).
pm_public_address() {
    local address
    address=$(pm_server primary_ipv4)
    [ -n "$address" ] || address=$(pm_server primary_ipv6)
    [ -n "$address" ] || address=$(pm_server hostname)
    printf '%s' "$address"
}

# pm_enable_forwarding NAME: turn on IPv4 and IPv6 forwarding, kept across reboots.
pm_enable_forwarding() {
    printf 'net.ipv4.ip_forward = 1\nnet.ipv6.conf.all.forwarding = 1\n' >"/etc/sysctl.d/99-proxmotion-$1.conf"
    sysctl -q -p "/etc/sysctl.d/99-proxmotion-$1.conf"
}
