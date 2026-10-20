#!/bin/bash
# Stops app scripts the panel started with systemd-run (docs/adr/0030). The
# panel runs this through the guest agent as
#
#   bash -c "<this file>" proxmotion-stop NAME…
#
# NAME is a unit the runner was started as (NAME.service). A unit that is not
# running counts as stopped; the exit status is non-zero only when one is
# still active afterwards.
set -uo pipefail

running() {
    case "$(systemctl show -p ActiveState --value "$1.service" 2>/dev/null)" in
        active | activating | deactivating | reloading | refreshing) return 0 ;;
        *) return 1 ;;
    esac
}

any_running() {
    local name

    for name in "$@"; do
        running "$name" && return 0
    done

    return 1
}

for name in "$@"; do
    case "$name" in
        *[!A-Za-z0-9-]* | '')
            echo "Invalid unit name: $name" >&2
            exit 2
            ;;
    esac
done

for name in "$@"; do
    running "$name" && systemctl kill --signal=TERM "$name.service" 2>/dev/null
done

for _ in 1 2 3 4 5 6 7 8 9 10; do
    any_running "$@" || exit 0
    sleep 1
done

for name in "$@"; do
    running "$name" && systemctl kill --signal=KILL "$name.service" 2>/dev/null
done

sleep 2

for name in "$@"; do
    if running "$name"; then
        echo "$name is still running." >&2
        exit 1
    fi
done

exit 0
