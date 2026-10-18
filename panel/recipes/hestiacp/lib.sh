#!/bin/bash
# Shared by the HestiaCP scripts.

HESTIA_BIN=/usr/local/hestia/bin

# hestia_installed: whether Hestia and its admin user exist.
hestia_installed() {
    [ -x "$HESTIA_BIN/v-list-user" ] && "$HESTIA_BIN/v-list-user" admin >/dev/null 2>&1
}

# hestia_password: a random password of 20 letters and digits.
hestia_password() {
    printf 'Hc%s7' "$(head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | cut -c 1-17)"
}

# hestia_set_admin_password PASSWORD: the password of Hestia's admin user.
hestia_set_admin_password() {
    "$HESTIA_BIN/v-change-user-password" admin "$1" >/dev/null || pm_fail "The HestiaCP administrator password could not be set."
}
