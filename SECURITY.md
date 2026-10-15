# Security Policy

Proxmotion manages customer infrastructure, so we take security reports seriously.

## Reporting a vulnerability

**Please do not report security vulnerabilities through public GitHub issues, discussions or pull requests.**

Use GitHub's **private vulnerability reporting** on this repository ("Security" tab → "Report a vulnerability").

Please include:

- A description of the issue and its impact (e.g. tenant isolation bypass, credential exposure, RCE)
- Steps to reproduce or a proof of concept
- Affected version / commit
- Any suggested fix, if you have one

We aim to acknowledge reports within **3 business days** and to keep you informed until the issue is resolved. We will credit reporters in the release notes unless you prefer to stay anonymous.

## Supported versions

Proxmotion is in early development. Until the first stable release, only the latest commit on `main` receives security fixes.

## Scope

Particularly relevant areas:

- Tenant isolation (a customer accessing another customer's server or data)
- Exposure of Proxmox API tokens, root passwords or app secrets
- Console proxy and SSO token handling
- Command execution inside VPSes through the App Marketplace
- Billing API authentication and authorization
