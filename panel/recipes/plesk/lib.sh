#!/bin/bash
# Shared by the Plesk scripts.

# plesk_password: a random password of 20 letters and digits, with at least
# one of each kind Plesk's default password policy asks for.
plesk_password() {
    printf 'Pm%s7' "$(head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | cut -c 1-17)"
}

# plesk_set_admin_password PASSWORD: the password of Plesk's admin user; works
# before the first login has finished the initial configuration.
plesk_set_admin_password() {
    plesk bin init_conf --set-admin-password -passwd "$1" >/dev/null || pm_fail "The Plesk administrator password could not be set."
}
