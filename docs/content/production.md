---
title: "Production and configuration"
weight: 9
lead: "The production image and every environment variable."
---

```bash
docker build -f Dockerfile.production -t php-sso:local .
```

The production image runs Apache as an unprivileged user on port 8080. Mount a volume at `/app/var`
(database and signing keys). Configuration through environment variables:

| Variable | Purpose |
| --- | --- |
| `SSO_PUBLIC_URL` | Public base URL; becomes the token issuer |
| `ADMIN_PASSWORD` | Password of the first administrator (required while the database is empty) |
| `ADMIN_EMAIL` | Email of that administrator (optional) |
| `SSO_APP_NAME` | Name shown on the pages |
| `SSO_LOGIN_IDENTIFIER` | What people type to sign in: `username` (default), `email` or `username_or_email`. The email modes need a different email address per user: an address shared by two people matches no one |
| `SSO_GOOGLE_CLIENT_ID`, `SSO_GOOGLE_CLIENT_SECRET` | Turn on "Continue with Google" |
| `SSO_GITHUB_CLIENT_ID`, `SSO_GITHUB_CLIENT_SECRET` | Turn on "Continue with GitHub" |
| `SSO_OIDC_ISSUER`, `SSO_OIDC_CLIENT_ID`, `SSO_OIDC_CLIENT_SECRET`, `SSO_OIDC_LABEL` | Turn on any other OpenID Connect provider (Microsoft, Okta, Keycloak...) under the button label you give |
| `SSO_MFA_POLICY` | Who must set up two-step verification: `optional` (default), `admins` or `everyone`. An administrator can change it at `/admin/security`, and what is saved there wins |
| `SSO_DB_DRIVER` | Where the data lives: `sqlite` (default), `mysql` or `pgsql`. See [Databases](#databases) |
| `SSO_DB_PATH` | The SQLite file, default `var/sso.sqlite` |
| `SSO_DB_HOST`, `SSO_DB_PORT`, `SSO_DB_NAME`, `SSO_DB_USER`, `SSO_DB_PASSWORD` | The MySQL or PostgreSQL server (the port defaults to 3306 and 5432) |
| `SSO_ALLOW_INSECURE_HTTP` | `1` lets `SSO_PUBLIC_URL` be a plain `http://` address that is not local. Without it the server refuses to start (see [Security](#security)) |
| `SSO_CSP` | The `Content-Security-Policy` to send instead of the default, for a theme that loads fonts or images from another server (see [Security](#security)) |
| `SSO_THEME_DIR` | Theme folder, default `/theme` |
| `SSO_DEBUG` | `1` shows theme errors instead of falling back |
| `SSO_TRUSTED_PROXIES` | Comma separated addresses or CIDR ranges of your reverse proxies. Only their `X-Forwarded-For` is believed; set it when behind a proxy, or IP rules see the proxy's address |
| `SSO_PKCE_OPTIONAL_FOR_CONFIDENTIAL_CLIENTS` | `1` stops requiring PKCE of confidential clients. Public clients always need it. It exists so the OpenID conformance suite can run (see `conformance/README.md`); leave it off in production |
| `SSO_DEMO_DATA` | `1` seeds a demo user, group and client with known passwords (development only) |

## Security

What the server does for you, and what it refuses to do:

- **It refuses to start in a setup that would weaken it,** and says why on every page and in the log: `SSO_PUBLIC_URL` must be
  an `https://` address (a reverse proxy may do the TLS; a local address such as `localhost` may use `http://`, and so may anything if you set
  `SSO_ALLOW_INSECURE_HTTP=1`), and `SSO_DEMO_DATA`, which creates people with well-known passwords, only runs on a local address.
- **The first administrator needs a real password.** `ADMIN_PASSWORD` must be at least 12 characters and not the same few characters
  repeated. It is only read while the database is empty.
- **Every response carries security headers:** a `Content-Security-Policy` that allows scripts, styles and fonts only from the server itself and
  forbids framing the pages, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `Cache-Control: no-store` (unless the response chose
  its own), and, over HTTPS, `Strict-Transport-Security`. If your theme needs another source (a font from a font service, say), set `SSO_CSP` to the
  whole policy you want; start from the default in `src/Http/SecurityHeaders.php`.
- **The browser session cookie** is `HttpOnly` and `SameSite=Lax`, and over HTTPS also `Secure` and named `__Host-sso_session`, which stops
  other sites and subdomains from setting it. PHP's session files are in `var/sessions`, not in the shared temporary folder.
- **`GET /healthz`** answers `200 {"status":"ok"}` when the server can reach its database and `503` when it cannot. It says nothing else, so a load
  balancer or monitor may call it. The image checks it with a Docker `HEALTHCHECK`.
- **SQLite runs in write-ahead-log mode** with a five second wait for a locked database, so readers and the writer do not block each other.
  It needs a local disk: keep `var/` off network file systems (NFS, SMB), where SQLite's locking is not reliable.

## Databases

Everything the server keeps (people, groups, clients, tokens, sessions, settings) is in one database. SQLite is the default and
needs nothing: the file is `var/sso.sqlite`. MySQL and PostgreSQL are there for when you want the data in a server you already
run, back up and monitor:

- **MySQL** 8.0.19 or later, with the `pdo_mysql` extension.
- **PostgreSQL** 13 or later, with the `pdo_pgsql` extension. Case-insensitive names use the `citext` extension, which the owner of the
  database may create.

Create an empty database and a user that owns it, then set `SSO_DB_DRIVER` and the `SSO_DB_*` variables. The tables are created on first
start. The production image includes both drivers.

The three behave the same: the application's test suite runs against every one of them. Nothing in the app depends on a particular
database. Another one is a new implementation of the `PhpSso\Store\Storage` interface (see [code layout](../layout/)).

Two things to know:

- **No migrations yet on MySQL and PostgreSQL.** A start creates missing tables, but it does not change a table that exists. SQLite
  databases from older releases are brought up to date on start; for the other two, a release that changes the schema will say what to run.
- **Housekeeping.** Run `bin/maintenance` from cron (for example hourly) to remove expired sessions, codes and tokens. The server also does it on
  about one request in a hundred.
