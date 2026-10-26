#!/bin/bash
# Shared by the WireGuard scripts (recipe.yaml: includes). Each client has
# /etc/wireguard/clients/<name>.key, .psk and .ip (its host number in
# 10.66.66.0/24 and fd42:66:66::/64); wg0.conf is rebuilt from them.

WG_DIR=/etc/wireguard
WG_CLIENTS=$WG_DIR/clients

# wg_settings: port, interface and endpoint of the server (sets variables).
wg_settings() {
    port=$(pm_app_input port)
    interface=$(pm_default_interface)
    endpoint=$(pm_public_address)

    [ -n "$interface" ] || pm_fail "The server has no IPv4 default route."
    [ -n "$endpoint" ] || pm_fail "The server's public address is unknown."
    case "$endpoint" in *:*) endpoint="[$endpoint]" ;; esac
}

# wg_check_name NAME: client names are lower-case letters, digits and dashes.
wg_check_name() {
    case "$1" in
        "" | -* | *- | *[!a-z0-9-]*) pm_fail "Invalid client name." ;;
    esac
}

# wg_add_client NAME: keys and the next free address, kept when they exist.
wg_add_client() {
    local name=$1 host
    umask 077
    mkdir -p "$WG_CLIENTS"
    [ -s "$WG_CLIENTS/$name.key" ] || wg genkey >"$WG_CLIENTS/$name.key"
    [ -s "$WG_CLIENTS/$name.psk" ] || wg genpsk >"$WG_CLIENTS/$name.psk"

    if [ ! -s "$WG_CLIENTS/$name.ip" ]; then
        for host in $(seq 2 254); do
            if ! grep -qx "$host" "$WG_CLIENTS"/*.ip 2>/dev/null; then
                echo "$host" >"$WG_CLIENTS/$name.ip"
                return 0
            fi
        done

        rm -f "$WG_CLIENTS/$name.key" "$WG_CLIENTS/$name.psk"
        pm_fail "No free client address is left (253 clients)."
    fi
}

# wg_clients: the client names, one per line.
wg_clients() {
    local file
    for file in "$WG_CLIENTS"/*.ip; do
        [ -e "$file" ] || continue
        basename "$file" .ip
    done
}

# wg_render: wg0.conf with every client as a peer.
wg_render() {
    local name host
    umask 077
    [ -s "$WG_DIR/server.key" ] || wg genkey >"$WG_DIR/server.key"

    {
        echo "[Interface]"
        echo "Address = 10.66.66.1/24, fd42:66:66::1/64"
        echo "ListenPort = ${port}"
        echo "PrivateKey = $(cat "$WG_DIR/server.key")"
        echo "PostUp = iptables -t nat -A POSTROUTING -s 10.66.66.0/24 -o ${interface} -j MASQUERADE; ip6tables -t nat -A POSTROUTING -s fd42:66:66::/64 -o ${interface} -j MASQUERADE"
        echo "PostDown = iptables -t nat -D POSTROUTING -s 10.66.66.0/24 -o ${interface} -j MASQUERADE; ip6tables -t nat -D POSTROUTING -s fd42:66:66::/64 -o ${interface} -j MASQUERADE"

        for name in $(wg_clients); do
            host=$(cat "$WG_CLIENTS/$name.ip")
            echo
            echo "# ${name}"
            echo "[Peer]"
            echo "PublicKey = $(wg pubkey <"$WG_CLIENTS/$name.key")"
            echo "PresharedKey = $(cat "$WG_CLIENTS/$name.psk")"
            echo "AllowedIPs = 10.66.66.${host}/32, fd42:66:66::${host}/128"
        done
    } >"$WG_DIR/wg0.conf"
}

# wg_client_conf NAME: the client's configuration file ($WG_CLIENTS/NAME.conf).
wg_client_conf() {
    local name=$1 host
    host=$(cat "$WG_CLIENTS/$name.ip")
    umask 077

    cat >"$WG_CLIENTS/$name.conf" <<CONF
[Interface]
PrivateKey = $(cat "$WG_CLIENTS/$name.key")
Address = 10.66.66.${host}/32, fd42:66:66::${host}/128
DNS = 1.1.1.1, 2606:4700:4700::1111

[Peer]
PublicKey = $(wg pubkey <"$WG_DIR/server.key")
PresharedKey = $(cat "$WG_CLIENTS/$name.psk")
Endpoint = ${endpoint}:${port}
AllowedIPs = 0.0.0.0/0, ::/0
PersistentKeepalive = 25
CONF
}

# wg_apply: load the peers without dropping connected clients, or start the VPN.
wg_apply() {
    if systemctl is-active --quiet wg-quick@wg0; then
        wg syncconf wg0 <(wg-quick strip wg0)
    else
        systemctl enable wg-quick@wg0
        systemctl restart wg-quick@wg0
    fi
}
