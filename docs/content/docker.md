---
title: "Run with Docker"
weight: 1
lead: "The published image is the way to run php-sso."
---

```bash
docker run -d --name sso -p 8080:8080 \
  -v sso-data:/app/var \
  -e SSO_PUBLIC_URL=https://sso.example.com \
  -e ADMIN_PASSWORD='choose-a-long-password' \
  ghcr.io/php-sso/php-sso:latest
```

That starts the server on port 8080. Sign in at `/admin` as `admin` with the password you gave. `ADMIN_PASSWORD` is only read
while the database is empty: it creates the first administrator, so change the password in the admin panel afterwards.

## Tags

Every release is published as `ghcr.io/php-sso/php-sso` with these tags, for `linux/amd64` and `linux/arm64`:

| Tag | What it is |
| --- | --- |
| `1.2.3` | Exactly that release. Use this in production, and move it on purpose |
| `1.2` | The newest `1.2.x` release |
| `1` | The newest `1.x` release (not published for the `0.x` releases, where any minor may change things) |
| `latest` | The newest release |

## What the image expects

- **`SSO_PUBLIC_URL`** is the address people reach it at, and the issuer in every token. The image speaks plain HTTP: put a reverse
  proxy in front for HTTPS, and tell the server which proxies to believe with `SSO_TRUSTED_PROXIES`.
- **A volume at `/app/var`.** It holds the SQLite database (unless you use [MySQL or PostgreSQL]({{< relref "production.md#databases" >}}))
  and the signing and encryption keys. Back it up. Without the keys, every token and every saved authenticator secret stops
  working. The container runs as `www-data` (user id 33), so a folder you mount instead of a named volume must be writable by that user:
  `chown 33:33 ./sso-data`.
- **A health check** is built in: `docker ps` shows the container as `healthy` while the server and its database answer, and `GET /healthz` is there for your own monitoring.
- **Every other setting** is an environment variable: see [Production and configuration]({{< relref "production.md" >}}).

## With a database server

The same image, with the database in its own container:

```yaml
services:
  sso:
    image: ghcr.io/php-sso/php-sso:latest
    ports: ["8080:8080"]
    environment:
      SSO_PUBLIC_URL: https://sso.example.com
      ADMIN_PASSWORD: choose-a-long-password
      SSO_DB_DRIVER: pgsql
      SSO_DB_HOST: db
      SSO_DB_NAME: sso
      SSO_DB_USER: sso
      SSO_DB_PASSWORD: choose-another-password
    volumes:
      - sso-keys:/app/var   # the keys; the data is in the database
    depends_on:
      db:
        condition: service_healthy
  db:
    image: postgres:17
    environment:
      POSTGRES_DB: sso
      POSTGRES_USER: sso
      POSTGRES_PASSWORD: choose-another-password
    volumes:
      - sso-db:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U sso -d sso"]
      interval: 3s
      retries: 20
volumes:
  sso-keys:
  sso-db:
```

## Your own look

Put your theme in a folder and either mount it at `/theme`, or build your own image from ours so that it travels with the image:

```dockerfile
FROM ghcr.io/php-sso/php-sso:1.2.3
COPY theme /theme
```

```bash
docker build -t sso-acme .
```

What goes in the folder, and what you can change, is on the [Theming]({{< relref "theming.md" >}}) page. The
[app skeleton](https://github.com/php-sso/app-skeleton) is a ready-made folder to start from.

## Upgrading

Change the tag, pull and recreate the container. The volume keeps the data. The new version brings the database schema up to date when it
starts (see [Migrations]({{< relref "production.md#migrations" >}})). To do that as a separate step instead, set `SSO_AUTO_MIGRATE=0`, run
`docker run --rm <the same options> ghcr.io/php-sso/php-sso:<tag> bin/migrate up` (or `docker exec <container> bin/migrate up`), and start the new
version after it. `bin/migrate status` shows what ran. Back up a MySQL database first: it cannot undo a migration that fails halfway.
