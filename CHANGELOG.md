# Changelog

All notable changes are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project follows [Semantic Versioning](https://semver.org/).

## 0.4.1 - 2026-11-24

### Fixed

- The note "You cannot lock or delete your own account." on a user's Danger tab had no space above it, so it stuck to the box above.

## 0.4.0 - 2026-11-24

### Added

- **IP rules per user.** Each user has an IP rules tab with allow and deny rules (addresses or CIDR ranges) that limit where that person may sign in from, on top of the general rules and those of each app. They are checked at sign-in and on every browser page of an open session, and the access test shows them as a step. Migration 0009 adds `user_ip_rules`. See [IP rules](docs/content/ip-rules.md).

### Changed

- The "Two-step verification is on" box with the reset button moved from a user's Details tab to the Danger tab.

## 0.3.0 - 2026-11-24

### Added

- **Resource indicators** (RFC 8707). Each client has **Allowed resources** on its Settings tab, and each registrar has the resources its registered apps may get on its Registration tab. An app sends `resource` with the authorization and token requests, and the access token is then valid only for that API: its audience is the resource and this server, no longer the app. A refresh can narrow the resources but not widen them. Needs `php-sso/oauth-server` 1.2. Migration 0008 adds `resources` to `oauth_codes`, `oauth_refresh_tokens` and `clients`. See [Tokens for one API](docs/content/resource-indicators.md).
- **Dynamic client registration** (RFC 7591 and 7592). A client with **May register apps** ticked on its Registration tab in the admin panel (and the built-in `client:register` scope) registers apps at `POST /register`; each app gets a registration access token to read, replace or delete only itself at `/register/{client_id}`. Per registrar, you choose the scopes its apps may get; they only use the authorization code and refresh grants. Registered apps are ordinary clients in the admin panel, marked as registered through the API. No settings in the environment. Needs `php-sso/oauth-server` 1.2. Migrations 0006 and 0007 add `clients.registration` and `clients.registrar`. See [Registering apps from code](docs/content/client-registration.md).

### Changed

- **Tests are split in two suites.** `tests/unit` (no database, no network, runs in seconds) and `tests/integration` (real databases, a local server, the test mailbox). Run them with `composer test:unit` and `composer test:integration`; `composer test` runs both. `SuiteRulesTest` keeps database use out of the unit suite.

### Fixed

- An administrator can save a client whose ID has capital letters (the random IDs of apps that registered themselves). The lowercase rule now applies only when the ID is being created or changed.

### Security

- **Twig 3.31.1.** Fixes nine advisories in Twig 3.30.0: sandbox bypasses (CVE-2026-133763 to CVE-2026-133768, CVE-2026-133770, CVE-2026-133772) and a case of missing autoescaping (CVE-2026-133771). The dependency audit in CI is green again.

## 0.2.0 - 2026-11-22

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
- **SCIM bulk operations.** `POST /scim/v2/Bulk`: up to 100 operations and 1 MiB in one request, with `bulkId` references between operations, `failOnErrors`, and each operation logged like a single request. Needs `php-sso/scim-server` 1.1.
- **Signed SAML requests.** A connection can be set to sign its sign-in requests (RSA-SHA256). The server makes its own key and certificate, publishes the certificate in the metadata and on the connection's page, and `bin/keys saml-stage`, `saml-activate`, `saml-discard` and `saml-status` replace it without a gap. Needs `php-sso/saml` 1.1. Migration 0005 adds `saml_connections.sign_requests`.
- **`ADMIN_USERNAME`** sets the username of the first administrator (it was always `admin`). On a person's admin page, the failed sign-in and password reset boxes moved to the Danger tab.
- **Emails: a theme can restyle the button** by extending `_email.html.twig` and overriding the `email_button_style` block, without copying the text of each email.
- **Changed: `/logout` asks first.** A link to `/logout` now shows "Sign out?" and signs out only on the button (a `POST` with the form token), so another website cannot sign someone out. Themes can replace the new `logout` page. Anything that sent a plain `GET` and expected to be signed out now needs one more click; apps should use `/end-session`.
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
