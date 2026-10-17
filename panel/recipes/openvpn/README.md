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
