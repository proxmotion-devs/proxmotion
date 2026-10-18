# OpenVPN

An OpenVPN server (`openvpn-server@server`) with its own Easy-RSA certificate authority
(EC keys, certificates valid ten years) and one client profile. Client traffic is routed
through the server with NAT (`10.8.0.0/24`); DNS is pushed as 1.1.1.1 and 1.0.0.1.

| Input      | Meaning                                   |
| ---------- | ----------------------------------------- |
| `port`     | Port the server listens on (default 1194) |
| `protocol` | `udp` (default) or `tcp`                  |

Outputs: `client.ovpn` (import it into an OpenVPN client) and the server endpoint.

The CA, certificates and tls-crypt key are kept when the recipe runs again, so the
client profile stays valid; port and protocol can be changed by running it again.

## Clients and uninstall

The first client is named `client`. Actions (from the billing system's client area or
the admin panel):

| Action          | Input  | Result                                                           |
| --------------- | ------ | ---------------------------------------------------------------- |
| `add_client`    | `name` | A new certificate and its `client.ovpn` profile                  |
| `revoke_client` | `name` | The certificate is revoked (CRL); the server restarts to drop it |
| `list_clients`  | –      | The names of the clients that can connect                        |

A revoked name cannot be used again. Uninstalling stops the VPN and deletes its
configuration, certificate authority and profiles; the OpenVPN packages stay installed.

## Upgrade

Installations of versions 1.1.x and 1.2.x can be upgraded: newer OpenVPN and Easy-RSA
packages, a fresh revocation list and a restart. The certificate authority, the server
configuration and the clients' profiles are kept.
