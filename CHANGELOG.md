# Changelog

All notable changes are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project follows [Semantic Versioning](https://semver.org/).

## Unreleased

### Added

- **More databases.** MySQL 8.0.19 or later and PostgreSQL 13 or later, besides SQLite (`SSO_DB_DRIVER` and `SSO_DB_*`), behind a `Storage` interface. The test suite runs on all three.
- **Schema migrations** with `bin/migrate status|up` and `SSO_AUTO_MIGRATE`. An older version refuses to run against a database a newer one has upgraded.
- **A published Docker image**, `ghcr.io/php-sso/php-sso`, for every version tag, and `composer create-project php-sso/php-sso` as the other way to install. `docker compose` is documented as the development setup only.
- **Security:** a `Content-Security-Policy` and other headers on every response (`SSO_CSP` to change the policy), a `Secure` `__Host-` session cookie over HTTPS, session files in `var/sessions`, `GET /healthz` and a Docker `HEALTHCHECK`, SQLite in write-ahead-log mode, and a weekly `composer audit` in CI.
- **Safe startup:** the server refuses to start with a public `http://` address (`SSO_ALLOW_INSECURE_HTTP=1` to accept it), with demo data on a public address, or with a first administrator password of fewer than 12 characters.
- **Signing key rotation** with `bin/keys list|rotate|prune` (`SSO_KEY_RETENTION_DAYS`): a new key is published before it signs, and a retired one stays published until its tokens have expired.
- **Backup and restore** with `bin/backup` and `bin/restore`: one file with the data and the keys, consistent while the server runs, and restorable into any of the three databases (also how to move from SQLite to MySQL or PostgreSQL).
- **Housekeeping** with `bin/maintenance`, and automatically on about one request in a hundred.
- **An audit log** at `/admin/audit`: every change made in the admin panel, the changes people make to their own account, and key, backup and restore commands. Entries are kept 365 days (`SSO_AUDIT_RETENTION_DAYS`) and can be streamed to the error log (`SSO_AUDIT_STREAM`).
- **Threat model and repeatable checks.** A published threat model with its known gaps, an upgrade test from a released version (`tests/upgrade/run.sh`), and a load and race test (`tests/load/run.py`). The OpenID conformance run on this build: 0 failures.
- **Password reset by email.** With `SSO_SMTP_HOST` and `SSO_MAIL_FROM` set, the sign-in page offers "Forgot your password?" and people get a link to choose a new password. The link works once and expires
  (`SSO_PASSWORD_RESET_MINUTES`), only a hash of it is stored, the answer is the same for accounts that exist and those that do not, requests are limited per address and per account, and using it ends every session of the
  person and sends them a notice. Administrators can send the link from a person's page. The emails are themable (plain text and HTML), the mail client is built in (STARTTLS or TLS with the certificate always checked, PLAIN or LOGIN), and
  `bin/mail-test` checks the settings. The development setup starts Mailpit, an inbox for the mail the server sends.
- **Paged lists:** Users, Groups and Clients show 25 to a page with a search box, and cost the same however many there are (the dashboard and the access test no longer load everyone).
- **Back-channel logout is retried:** a notification an app did not accept is sent again after 1 minute, 5 minutes, 30 minutes, 2 hours and 12 hours, with a fresh token each time, and then given up on and audited (`logout.undelivered`).
- Documentation sections for running with Docker and with Composer, security, backup and restore, and development.

### Changed

Read this before upgrading.

- **Existing SQLite databases are upgraded in place** when the new version starts (the audit log table is added). Copy `var/sso.sqlite` first.
- **Everyone signs in again once over HTTPS:** the session cookie is now named `__Host-sso_session`.
- **The server may refuse to start** where it used to run: a public `http://` `SSO_PUBLIC_URL`, `SSO_DEMO_DATA` on a public address, a weak `ADMIN_PASSWORD` on an empty database (see Added).
- **`config/config.php`:** the `database` setting is now `storage`, an array with the driver and its settings. This only matters if you edited that file.
- **Run `bin/maintenance` every minute or every few minutes** rather than hourly: it also retries logout notifications. The server does the same on some requests if you do not.
- **The access test** takes a username (or an id) for the person instead of a drop-down of everyone.
- **For code that builds on the app:** the store classes (`PhpSso\Store\Users` and the others) are interfaces now, their SQL implementations are in `PhpSso\Store\Sql` (`SqlUsers` and so on), and `PhpSso\Database` is `PhpSso\Store\Sql\Connection`.

## 0.1.0 - 2026-11-21

First public release.

- OpenID Connect provider on `php-sso/oauth-server`: authorization code with PKCE, refresh tokens, client credentials, device
  authorization, discovery, JWKS, logout and back-channel logout.
- Admin panel: users, groups, roles, clients, scopes you add yourself, IP rules, sign-in history and an access test.
- Sign-in protection: throttling, two-step verification with a QR code drawn on the server, notices people must accept, required steps.
- Sign in with Google, GitHub or any OpenID Connect provider, and through a company's SAML identity provider (`php-sso/saml`).
- SCIM 2.0 provisioning of users and groups (`php-sso/scim-server`).
- Themeable pages, a demo client, and Docker setups for development and production.
