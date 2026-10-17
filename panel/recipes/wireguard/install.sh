#!/bin/bash
# A WireGuard server (wg0) with one client. Keys are generated inside the
# server and kept when the script runs again; the configuration is rewritten.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"

port=$(pm_input port)
interface=$(pm_default_interface)
endpoint=$(pm_public_address)

[ -n "$interface" ] || pm_fail "The server has no IPv4 default route."
[ -n "$endpoint" ] || pm_fail "The server's public address is unknown."
case "$endpoint" in *:*) endpoint="[$endpoint]" ;; esac

pm_step 10 "Installing WireGuard"
pm_apt_update
pm_apt_install wireguard wireguard-tools iptables

pm_step 50 "Creating keys"
umask 077
mkdir -p /etc/wireguard/clients
[ -s /etc/wireguard/server.key ] || wg genkey >/etc/wireguard/server.key
[ -s /etc/wireguard/clients/client.key ] || wg genkey >/etc/wireguard/clients/client.key
[ -s /etc/wireguard/clients/client.psk ] || wg genpsk >/etc/wireguard/clients/client.psk
server_public=$(wg pubkey </etc/wireguard/server.key)
client_public=$(wg pubkey </etc/wireguard/clients/client.key)

pm_step 70 "Configuring the VPN"
cat >/etc/wireguard/wg0.conf <<CONF
[Interface]
Address = 10.66.66.1/24, fd42:66:66::1/64
ListenPort = ${port}
PrivateKey = $(cat /etc/wireguard/server.key)
PostUp = iptables -t nat -A POSTROUTING -s 10.66.66.0/24 -o ${interface} -j MASQUERADE; ip6tables -t nat -A POSTROUTING -s fd42:66:66::/64 -o ${interface} -j MASQUERADE
PostDown = iptables -t nat -D POSTROUTING -s 10.66.66.0/24 -o ${interface} -j MASQUERADE; ip6tables -t nat -D POSTROUTING -s fd42:66:66::/64 -o ${interface} -j MASQUERADE

[Peer]
PublicKey = ${client_public}
PresharedKey = $(cat /etc/wireguard/clients/client.psk)
AllowedIPs = 10.66.66.2/32, fd42:66:66::2/128
CONF

cat >/etc/wireguard/clients/client.conf <<CONF
[Interface]
PrivateKey = $(cat /etc/wireguard/clients/client.key)
Address = 10.66.66.2/32, fd42:66:66::2/128
DNS = 1.1.1.1, 2606:4700:4700::1111

[Peer]
PublicKey = ${server_public}
PresharedKey = $(cat /etc/wireguard/clients/client.psk)
Endpoint = ${endpoint}:${port}
AllowedIPs = 0.0.0.0/0, ::/0
PersistentKeepalive = 25
CONF

pm_enable_forwarding wireguard

pm_step 90 "Starting the VPN"
systemctl enable wg-quick@wg0
systemctl restart wg-quick@wg0

pm_output_file client.conf /etc/wireguard/clients/client.conf
pm_output endpoint "${endpoint}:${port}"
