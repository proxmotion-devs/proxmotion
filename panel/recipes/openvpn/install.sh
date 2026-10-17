#!/bin/bash
# An OpenVPN server with an Easy-RSA certificate authority and one client
# profile. The CA and certificates are kept when the script runs again; the
# configuration is rewritten.
set -euo pipefail
# shellcheck source=/dev/null
. "$PROXMOTION_APP_DIR/proxmotion.sh"

port=$(pm_input port)
protocol=$(pm_input protocol)
interface=$(pm_default_interface)
address=$(pm_public_address)

[ -n "$interface" ] || pm_fail "The server has no IPv4 default route."
[ -n "$address" ] || pm_fail "The server's public address is unknown."

pm_step 10 "Installing OpenVPN"
pm_apt_update
pm_apt_install openvpn easy-rsa openssl iptables

pm_step 40 "Creating the certificate authority"
pki=/etc/openvpn/easy-rsa
[ -d "$pki" ] || make-cadir "$pki"
export EASYRSA_BATCH=1 EASYRSA_ALGO=ec EASYRSA_CURVE=prime256v1 EASYRSA_CERT_EXPIRE=3650 EASYRSA_REQ_CN="Proxmotion OpenVPN CA"
cd "$pki"
[ -f pki/ca.crt ] || { ./easyrsa init-pki && ./easyrsa build-ca nopass; }
[ -f pki/issued/server.crt ] || ./easyrsa build-server-full server nopass
[ -f pki/issued/client.crt ] || ./easyrsa build-client-full client nopass
umask 077
[ -s /etc/openvpn/server/tls-crypt.key ] || openvpn --genkey secret /etc/openvpn/server/tls-crypt.key
install -m 600 pki/ca.crt pki/issued/server.crt pki/private/server.key /etc/openvpn/server/

pm_step 70 "Configuring the VPN"
exit_notify=""
[ "$protocol" = udp ] && exit_notify="explicit-exit-notify 1"
cat >/etc/openvpn/server/server.conf <<CONF
port ${port}
proto ${protocol}
dev tun
ca ca.crt
cert server.crt
key server.key
dh none
ecdh-curve prime256v1
tls-crypt tls-crypt.key
topology subnet
server 10.8.0.0 255.255.255.0
push "redirect-gateway def1 bypass-dhcp"
push "dhcp-option DNS 1.1.1.1"
push "dhcp-option DNS 1.0.0.1"
keepalive 10 120
data-ciphers AES-256-GCM:AES-128-GCM:CHACHA20-POLY1305
user nobody
group nogroup
persist-key
persist-tun
verb 3
${exit_notify}
CONF

cat >/etc/systemd/system/proxmotion-openvpn-nat.service <<UNIT
[Unit]
Description=NAT for OpenVPN clients (Proxmotion)
Before=openvpn-server@server.service

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/bin/sh -c 'iptables -t nat -C POSTROUTING -s 10.8.0.0/24 -o ${interface} -j MASQUERADE 2>/dev/null || iptables -t nat -A POSTROUTING -s 10.8.0.0/24 -o ${interface} -j MASQUERADE'
ExecStop=/bin/sh -c 'iptables -t nat -D POSTROUTING -s 10.8.0.0/24 -o ${interface} -j MASQUERADE || true'

[Install]
WantedBy=multi-user.target
UNIT

pm_enable_forwarding openvpn

pm_step 85 "Starting the VPN"
systemctl daemon-reload
systemctl enable proxmotion-openvpn-nat.service openvpn-server@server
systemctl restart proxmotion-openvpn-nat.service openvpn-server@server

profile=/etc/openvpn/client.ovpn
{
    echo "client"
    echo "dev tun"
    echo "proto ${protocol}"
    echo "remote ${address} ${port}"
    echo "resolv-retry infinite"
    echo "nobind"
    echo "persist-key"
    echo "persist-tun"
    echo "remote-cert-tls server"
    echo "data-ciphers AES-256-GCM:AES-128-GCM:CHACHA20-POLY1305"
    echo "verb 3"
    echo "<ca>"
    cat pki/ca.crt
    echo "</ca>"
    echo "<cert>"
    openssl x509 -in pki/issued/client.crt
    echo "</cert>"
    echo "<key>"
    cat pki/private/client.key
    echo "</key>"
    echo "<tls-crypt>"
    cat /etc/openvpn/server/tls-crypt.key
    echo "</tls-crypt>"
} >"$profile"

pm_output_file client.ovpn "$profile"
pm_output endpoint "${address}:${port}/${protocol}"
