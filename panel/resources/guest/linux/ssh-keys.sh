#!/bin/sh
# Proxmotion: the SSH keys the panel manages on the server, Linux and FreeBSD
# (docs/adr/0010, 0048 and 0049 in the Proxmotion repository; POSIX sh and awk
# only, so both can run it). Installed and run by Proxmotion through
# the QEMU guest agent; changes to this file are overwritten.
#
# /etc/proxmotion/ssh-keys.vars (written by Proxmotion):
#   username=root
#   ssh_keys=ssh-ed25519 AAAA... user@host          one line per key the user should have
#   removed_ssh_keys=ssh-ed25519 AAAA... old@host   one line per key to take away
#
# Removes every line of the user's authorized_keys that contains the key of a
# removed_ssh_keys line (also lines with options), then appends each key of
# ssh_keys that is not there yet. Lines Proxmotion does not know stay. Safe to
# run any number of times.
set -eu

VARS=/etc/proxmotion/ssh-keys.vars

user=$(sed -n 's/^username=//p' "$VARS" | head -n 1)

# Only user name characters reach the commands below.
case "$user" in
    '' | -* | *[!a-zA-Z0-9._-]*)
        echo "Invalid user name: $user" >&2
        exit 2
        ;;
esac

home=$(awk -F: -v user="$user" '$1 == user { print $6; exit }' /etc/passwd)

if [ -z "$home" ] || [ ! -d "$home" ]; then
    echo "The user $user has no home directory." >&2
    exit 2
fi

dir="$home/.ssh"
file="$dir/authorized_keys"

if [ ! -d "$dir" ]; then
    mkdir -m 700 "$dir"
    chown "$user" "$dir"
fi

[ -f "$file" ] || : > "$file"

tmp=$(mktemp "$dir/.authorized_keys.XXXXXX")
trap 'rm -f "$tmp"' EXIT

# A key is told by its base64 blob, the field after its type; options may
# come before the type, so every field of a line is compared.
awk '
    FNR == NR {
        if (sub(/^ssh_keys=/, "") && split($0, f, " ") >= 2 && !(f[2] in wanted)) {
            wanted[f[2]] = 1
            order[++n] = $0
        } else if (sub(/^removed_ssh_keys=/, "") && split($0, f, " ") >= 2) {
            removed[f[2]] = 1
        }
        next
    }
    {
        for (i = 1; i <= NF; i++) {
            if (($i in removed) && !($i in wanted)) {
                next
            }
        }
        for (i = 1; i <= NF; i++) {
            if ($i in wanted) {
                present[$i] = 1
            }
        }
        print
    }
    END {
        for (i = 1; i <= n; i++) {
            split(order[i], f, " ")
            if (!(f[2] in present)) {
                print order[i]
            }
        }
    }
' "$VARS" "$file" > "$tmp"

chmod 600 "$tmp"
chown "$user" "$tmp"
mv -f "$tmp" "$file"
trap - EXIT

if command -v restorecon > /dev/null 2>&1; then
    restorecon -R "$dir" || true
fi
