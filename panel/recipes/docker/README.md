# Docker

Installs Docker Engine, the Docker CLI, containerd and the Compose and Buildx plugins
from [Docker's apt repository](https://docs.docker.com/engine/install/) and starts the
service. Output: the installed Docker version.

Running it again, or upgrading an installation of version 1.1.x or 1.2.x, updates Docker
to the repository's current version; images, containers and volumes are kept.

Uninstalling stops Docker and removes its packages and repository. Images, containers
and volumes in `/var/lib/docker` and `/var/lib/containerd` are kept.
