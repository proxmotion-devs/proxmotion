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

## Clients and uninstall

The first client is named `client`. Actions (from the billing system's client area or
the admin panel):

| Action          | Input  | Result                                                  |
| --------------- | ------ | ------------------------------------------------------- |
| `add_client`    | `name` | A new peer with the next free address and `client.conf` |
| `revoke_client` | `name` | The peer and its keys are deleted                       |
| `list_clients`  | –      | The client names                                        |

Peers are loaded with `wg syncconf`, so connected clients stay connected. Up to 253
clients. Uninstalling stops the VPN and deletes its configuration and keys; the
WireGuard packages stay installed.

## Upgrade

Installations of versions 1.1.x and 1.2.x can be upgraded: newer WireGuard packages,
`wg0.conf` rebuilt from the clients, and a restart. Keys, clients and their
configurations are kept.
