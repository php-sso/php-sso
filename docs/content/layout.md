---
title: "Code layout"
weight: 41
lead: "Where things are in the source."
group: "Contribute"
---

- `public/index.php`: the front controller. It builds everything for the request, runs the [startup checks]({{< relref "security/startup-checks.md" >}}), answers `/healthz`, starts the session and sends every response through the security headers
- `src/Sso.php`: HTTP endpoints (PSR-7 request in, response out)
- `src/Admin/` + `templates/admin/`: the admin panel (Twig): `AdminController`, the picker for who is in a group, and the pager the lists share
- `src/Pages.php`, `src/Account/`, `templates/pages/`: themeable pages (login, account, errors)
- `src/Auth/`: signing in: the login steps and their controllers, throttling, two-step verification (`Mfa/`), outside providers and SAML, the steps a person must take before they are in (`Actions/`), what people type to identify themselves (`Identifier/`), and impersonation
- `src/OAuth/`: what `php-sso/oauth-server` needs from us: clients from the database, the access policy, the sign-in, "no access" and logout screens, the device page, the retry of [logout notifications]({{< relref "signing-out.md" >}}) that failed, and what it asks of the token stores
- `src/Mail/`: sending email: the message, the SMTP client (with no library behind it) and the emails the server sends, made from templates a theme can replace
- `src/Audit/`: the [audit log]({{< relref "security/audit-log.md" >}}): `Audit` writes entries, and `AdminActions` and `AccountActions` say what each change is called and what is kept of it
- `src/Backup/`: [backup and restore]({{< relref "backup.md" >}}): the exporter, the importer and the list of tables a backup holds
- `src/Http/`: the [security headers]({{< relref "security/headers.md" >}})
- `src/Startup/`: what stops the server from starting when its setup is unsafe
- `src/Store/`: the storage interfaces (users, groups, clients, tokens and the rest) and `Storage`, the one door to them. `src/Store/Sql/` implements them for SQLite, MySQL and PostgreSQL, and is the only place that knows about SQL: the stores write the SQL the three share, and a `Dialect` (with one schema per database) supplies the rest. `src/Store/Sql/Migrations/` changes the schema (see [writing a migration]({{< relref "development.md#writing-a-migration" >}}))
- `src/Integration/Saml/`: what `php-sso/saml` needs from us: the company providers and the requests waiting for an answer, on top of the stores
- `src/Integration/Scim/`: what `php-sso/scim-server` needs from us: the SCIM clients, the people and groups they manage, accounts, our groups and the request log, each on top of the stores
- `src/Net/`: client address handling and CIDR ranges for the IP rules, and a small PSR-18 HTTP client for back-channel logout
- `src/Qr/`: the QR code for two-step verification, drawn on the server as SVG
- `src/Keys.php`: the [signing keys](../security/signing-keys/) and their rotation; `src/Claims.php`: what goes into the tokens; `src/IpPolicy.php` and `src/AccessTester.php`: the IP rules and the admin's access test; `src/Seeder.php`: what a new database starts with
- `bin/`: the commands: `maintenance`, `keys`, `migrate`, `backup`, `restore` and `mail-test`
- `config/config.php`: scopes, TTLs, the environment variables and the first-start seed data
- `client/public/index.php`: demo relying party; verifies the ID token signature, `iss`, `aud` and `nonce`
- `conformance/`: runs the OpenID Connect conformance suite against a throwaway copy
- `docker/`, `Dockerfile`, `Dockerfile.production`, `docker-compose.yml`: the production image and the development setup
