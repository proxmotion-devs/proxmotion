#!/bin/bash
# Shared by the CyberPanel scripts.

# cyberpanel_installed: whether CyberPanel finished installing (the installer
# creates /usr/bin/adminPass at its very end).
cyberpanel_installed() {
    [ -d /usr/local/CyberCP ] && [ -x /usr/bin/adminPass ]
}

# cyberpanel_password: a random password of 20 letters and digits.
cyberpanel_password() {
    printf 'Cp%s7' "$(head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | cut -c 1-17)"
}

# cyberpanel_set_admin_password PASSWORD: the password of CyberPanel's admin
# user (CyberPanel's adminPass, which also restarts the panel).
cyberpanel_set_admin_password() {
    /usr/bin/adminPass "$1" >/dev/null || pm_fail "The CyberPanel administrator password could not be set."
}
