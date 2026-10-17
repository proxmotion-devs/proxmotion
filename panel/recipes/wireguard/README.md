# WireGuard

A WireGuard VPN server on interface `wg0` with one client. The client's IPv4 and IPv6
traffic is routed through the server (NAT to the server's addresses; the tunnel uses
`10.66.66.0/24` and `fd42:66:66::/64`).

| Input  | Meaning                                        |
| ------ | ---------------------------------------------- |
| `port` | UDP port the server listens on (default 51820) |

Outputs: `client.conf` (import it into a WireGuard client) and the server endpoint.

Keys are generated inside the server and kept when the recipe runs again, so an existing
client configuration stays valid; the port can be changed by running it again.
