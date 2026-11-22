---
title: "Production and configuration"
weight: 10
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
| `SSO_AUTO_MIGRATE` | `1` (default) brings the database schema up to date when the server starts. `0` makes the server refuse to serve while migrations are pending, and you run `bin/migrate up` yourself. See [Databases](#databases) |
| `SSO_ALLOW_INSECURE_HTTP` | `1` lets `SSO_PUBLIC_URL` be a plain `http://` address that is not local. Without it the server refuses to start (see [Safe startup]({{< relref "security/startup-checks.md" >}})) |
| `SSO_CSP` | The `Content-Security-Policy` to send instead of the default, for a theme that loads fonts or images from another server (see [Security headers]({{< relref "security/headers.md" >}})) |
| `SSO_KEY_RETENTION_DAYS` | How long a signing key that has been replaced stays published, default 30 (see [Signing keys]({{< relref "security/signing-keys.md" >}})) |
| `SSO_THEME_DIR` | Theme folder, default `/theme` |
| `SSO_DEBUG` | `1` shows theme errors instead of falling back |
| `SSO_TRUSTED_PROXIES` | Comma separated addresses or CIDR ranges of your reverse proxies. Only their `X-Forwarded-For` is believed; set it when behind a proxy, or IP rules see the proxy's address |
| `SSO_PKCE_OPTIONAL_FOR_CONFIDENTIAL_CLIENTS` | `1` stops requiring PKCE of confidential clients. Public clients always need it. It exists so the OpenID conformance suite can run (see `conformance/README.md`); leave it off in production |
| `SSO_DEMO_DATA` | `1` seeds a demo user, group and client with known passwords (development only) |

## Monitoring

`GET /healthz` answers `200 {"status":"ok"}` when the server can reach its database, and `503` when it cannot. It says nothing else, so a load balancer or
monitor may call it without being signed in. The Docker image checks it with a `HEALTHCHECK`, so `docker ps` shows `healthy` or `unhealthy`. If the server
refused to start because of a [setting]({{< relref "security/startup-checks.md" >}}), it fails too.

Run `bin/maintenance` from cron (for example hourly) to remove expired sessions, codes and tokens. The server also does it on about one request in a hundred.

## Databases

Everything the server keeps (people, groups, clients, tokens, sessions, settings) is in one database. SQLite is the default and
needs nothing: the file is `var/sso.sqlite`. MySQL and PostgreSQL are there for when you want the data in a server you already
run, back up and monitor:

- **MySQL** 8.0.19 or later, with the `pdo_mysql` extension.
- **PostgreSQL** 13 or later, with the `pdo_pgsql` extension. Case-insensitive names use the `citext` extension, which the owner of the
  database may create.

Create an empty database and a user that owns it, then set `SSO_DB_DRIVER` and the `SSO_DB_*` variables. The tables are created on first
start (see [Migrations](#migrations)). The production image includes both drivers.

The three behave the same: the application's test suite runs against every one of them. Nothing in the app depends on a particular
database. Another one is a new implementation of the `PhpSso\Store\Storage` interface (see [code layout](../layout/)).

**SQLite** runs in write-ahead-log mode with a five second wait for a locked database, so readers and the writer do not block each other, and a
write that meets another one waits instead of failing. It needs a local disk: keep `var/` off network file systems (NFS, SMB), where SQLite's locking is not reliable.

One more thing to know:

- **Housekeeping.** Run `bin/maintenance` from cron (for example hourly) to remove expired sessions, codes and tokens. The server also does it on
  about one request in a hundred.

### Migrations

The schema is changed by numbered migrations (`0001`, `0002`, ...). The table `schema_migrations` records which ones ran and when. The first
migration is the schema as it was when migrations began, so a database from an older release is recognised: its missing tables and columns
are added, and it is recorded as `0001`.

By default (`SSO_AUTO_MIGRATE=1`) the server runs the pending migrations when it connects. When the database is up to date, which is the usual
case, that costs one small query and changes nothing. Only one process migrates at a time (a lock in the database), so several servers
starting together are safe: the others wait, then find nothing left to do.

To migrate on purpose, for example from a deployment step before the new version takes traffic, set `SSO_AUTO_MIGRATE=0` and run:

```bash
bin/migrate status            # every migration, whether it ran, and when
bin/migrate up --dry-run      # what would run, nothing changed
bin/migrate up                # run the pending migrations
```

With `SSO_AUTO_MIGRATE=0` the server does not change the schema. While migrations are pending it serves nothing: every request, `/healthz`
included, answers `500` with the reason, and the same line is written to the error log. `bin/migrate` ignores the setting: it never migrates
when you only ask for `status`, and always migrates for `up`. (In the Docker image: `docker exec <container> bin/migrate up`.)

How a failure is handled depends on the database:

- **SQLite and PostgreSQL** can roll back schema changes. Each migration, and the line that records it, is one transaction: if a step fails,
  nothing of that migration is kept, and the next start tries it again.
- **MySQL cannot roll back schema changes.** Each step takes effect at once, and the migration is recorded only after its last step
  succeeded. A migration that fails halfway is therefore **retried from its first step, not rolled back**. The steps are written to be safe to
  run again (they check whether a table, column or index exists first), but after a failure, look at the error and at what the migration did
  before you start the server again. Take a backup before upgrading a MySQL database.

Move to a newer release by deploying it: it carries the migrations it needs. Do not remove rows from `schema_migrations`.
