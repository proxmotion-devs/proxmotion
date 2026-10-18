#!/bin/bash
# Shared by the aaPanel scripts.

# aapanel_installed: whether the panel is there.
aapanel_installed() {
    [ -x /etc/init.d/bt ] && [ -d /www/server/panel ]
}

# aapanel_login FILE: read what aaPanel printed (the installer's log or
# `bt default`) and report the address, username and password it shows. The
# address keeps aaPanel's port and security path; its host becomes the
# server's address, since aaPanel looks its own public address up online.
aapanel_login() {
    local text url rest host username password
    # Without colours, so the values are read as printed.
    text=$(sed 's/\x1b\[[0-9;]*m//g' "$1")

    url=$(printf '%s\n' "$text" | grep -i 'internet' | grep -oE 'https?://[^[:space:]]+' | tail -n 1 || true)
    username=$(printf '%s\n' "$text" | sed -n 's/^[[:space:]]*username:[[:space:]]*//p' | tail -n 1 | tr -d '[:space:]')
    password=$(printf '%s\n' "$text" | sed -n 's/^[[:space:]]*password:[[:space:]]*//p' | tail -n 1 | tr -d '[:space:]')

    if [ -n "$url" ]; then
        rest=${url#*://}
        rest=${rest#*:}
        host=$(pm_public_address)
        case "$host" in
            *:*) host="[$host]" ;;
        esac
        pm_output panel_url "${url%%://*}://${host}:${rest}"
    fi

    [ -z "$username" ] || pm_output username "$username"
    # aaPanel shows asterisks instead of a password it no longer knows.
    case "$password" in
        "" | *'*'*) return 1 ;;
    esac
    pm_output password "$password"
}
