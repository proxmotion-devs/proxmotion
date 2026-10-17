#!/bin/bash
# Shared by the OpenVPN scripts (recipe.yaml: includes). Clients are
# certificates of the Easy-RSA authority in /etc/openvpn/easy-rsa; revoked
# ones are refused through the CRL the server reads.

OVPN_PKI=/etc/openvpn/easy-rsa
OVPN_CLIENTS=/etc/openvpn/clients
export EASYRSA_BATCH=1 EASYRSA_ALGO=ec EASYRSA_CURVE=prime256v1 EASYRSA_CERT_EXPIRE=3650 EASYRSA_CRL_DAYS=3650 EASYRSA_REQ_CN="Proxmotion OpenVPN CA"

# ovpn_settings: port, protocol and address of the server (sets variables).
ovpn_settings() {
    port=$(pm_app_input port)
    protocol=$(pm_app_input protocol)
    address=$(pm_public_address)

    [ -n "$address" ] || pm_fail "The server's public address is unknown."
}

# ovpn_check_name NAME: client names are lower-case letters, digits and
# dashes; "server" is the server's certificate.
ovpn_check_name() {
    case "$1" in
        "" | server | -* | *- | *[!a-z0-9-]*) pm_fail "Invalid client name." ;;
    esac
}

# ovpn_has_client NAME: a valid (issued, not revoked) client certificate exists.
ovpn_has_client() {
    [ -f "$OVPN_PKI/pki/issued/$1.crt" ]
}

# ovpn_clients: the valid client names, one per line.
ovpn_clients() {
    local file name
    for file in "$OVPN_PKI"/pki/issued/*.crt; do
        [ -e "$file" ] || continue
        name=$(basename "$file" .crt)
        [ "$name" = server ] || echo "$name"
    done
}

# ovpn_publish_crl: the current revocation list where the server reads it.
ovpn_publish_crl() {
    (cd "$OVPN_PKI" && ./easyrsa gen-crl)
    install -m 644 "$OVPN_PKI/pki/crl.pem" /etc/openvpn/server/crl.pem
}

# ovpn_profile NAME: the client's profile ($OVPN_CLIENTS/NAME.ovpn).
ovpn_profile() {
    local name=$1
    umask 077
    mkdir -p "$OVPN_CLIENTS"

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
        cat "$OVPN_PKI/pki/ca.crt"
        echo "</ca>"
        echo "<cert>"
        openssl x509 -in "$OVPN_PKI/pki/issued/$name.crt"
        echo "</cert>"
        echo "<key>"
        cat "$OVPN_PKI/pki/private/$name.key"
        echo "</key>"
        echo "<tls-crypt>"
        cat /etc/openvpn/server/tls-crypt.key
        echo "</tls-crypt>"
    } >"$OVPN_CLIENTS/$name.ovpn"
}
