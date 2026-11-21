# Changelog

All notable changes are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project follows [Semantic Versioning](https://semver.org/).

## 0.1.0 - 2026-11-21

First public release.

- OpenID Connect provider on `php-sso/oauth-server`: authorization code with PKCE, refresh tokens, client credentials, device
  authorization, discovery, JWKS, logout and back-channel logout.
- Admin panel: users, groups, roles, clients, scopes you add yourself, IP rules, sign-in history and an access test.
- Sign-in protection: throttling, two-step verification with a QR code drawn on the server, notices people must accept, required steps.
- Sign in with Google, GitHub or any OpenID Connect provider, and through a company's SAML identity provider (`php-sso/saml`).
- SCIM 2.0 provisioning of users and groups (`php-sso/scim-server`).
- Themeable pages, a demo client, and Docker setups for development and production.
