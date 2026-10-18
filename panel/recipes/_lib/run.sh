#!/bin/bash
# Runs one script of a recipe (docs/adr/0015, 0016). The panel writes the
# recipe into the app's directory and starts this runner detached with
# systemd-run; the outcome ends up in status.json of the run's directory,
# which the panel reads through the guest agent.
#
#   run.sh                              install.sh in the app's directory → "installed"
#   run.sh SCRIPT RUN_DIR               an uninstall or an action → "succeeded"
#
# SCRIPT is relative to the app's directory; RUN_DIR holds the run's
# inputs.json, status.json, outputs.json, outputs/ and log.
set -uo pipefail

PROXMOTION_APP_DIR="$(cd "$(dirname "$0")" && pwd)"
script="${1:-install.sh}"
PROXMOTION_RUN_DIR="${2:-$PROXMOTION_APP_DIR}"
done_status=installed
done_step=Installed
[ $# -ge 2 ] && { done_status=succeeded; done_step=Done; }
export PROXMOTION_APP_DIR PROXMOTION_RUN_DIR
export DEBIAN_FRONTEND=noninteractive
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"

chmod 700 "$PROXMOTION_APP_DIR"
mkdir -p "$PROXMOTION_RUN_DIR"
cd "$PROXMOTION_RUN_DIR" || exit 1
rm -f failure state outputs.json
rm -rf outputs
mkdir -p outputs
log="$PROXMOTION_RUN_DIR/run.log"
: >"$log"

case "$script" in
    /* | *..*)
        pm_status failed 0 "Preparing" "Invalid script path."
        exit 1
        ;;
esac

pm_step 0 "Preparing"

# Right after the first boot (an app ordered with the server or a reinstall),
# cloud-init may still be updating packages.
pm_wait_for_boot 600 >>"$log" 2>&1
pm_step 0 "Preparing"

if ! command -v jq >/dev/null 2>&1; then
    if command -v apt-get >/dev/null 2>&1; then
        { pm_apt_update && pm_apt_install jq; } >>"$log" 2>&1
    elif command -v dnf >/dev/null 2>&1; then
        dnf install -y -q jq >>"$log" 2>&1
    fi
fi

if ! command -v jq >/dev/null 2>&1; then
    pm_status failed 0 "Preparing" "jq could not be installed."
    exit 1
fi

# The template's family decided that the app fits; /etc/os-release has the last word.
accepted=$(jq -r '(.os_release // []) | join(" ")' "$PROXMOTION_APP_DIR/server.json" 2>/dev/null || true)

if [ -n "$accepted" ] && [ -r /etc/os-release ]; then
    # shellcheck source=/dev/null
    os_ids=$(. /etc/os-release && echo "${ID:-} ${ID_LIKE:-}")
    # shellcheck source=/dev/null
    os_name=$(. /etc/os-release && echo "${PRETTY_NAME:-${ID:-this operating system}}")
    fits=

    for id in $os_ids; do
        for wanted in $accepted; do
            [ "$id" = "$wanted" ] && fits=1
        done
    done

    if [ -z "$fits" ]; then
        pm_status failed 0 "Preparing" "This app does not support ${os_name}."
        exit 1
    fi
fi

echo '{}' >outputs.json

if bash "$PROXMOTION_APP_DIR/$script" >>"$log" 2>&1; then
    pm_status "$done_status" 100 "$done_step"
    exit 0
else
    code=$?
fi

if [ -s failure ]; then
    reason=$(head -n 1 failure)
else
    reason="exit code $code: $(grep -v '^[[:space:]]*$' "$log" | tail -n 1)"
fi

pm_status failed "$(pm_current progress)" "$(pm_current step)" "${reason:0:500}"
exit "$code"
