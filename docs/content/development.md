---
title: "Development"
weight: 15
lead: "Run the demo, and the tests, from a checkout."
---

This is how to work on php-sso, not how to run it: for that, see [Run with Docker]({{< relref "docker.md" >}}) and
[Install with Composer]({{< relref "composer.md" >}}). From a checkout of the repository:

```bash
docker compose up --build
```

- SSO server: http://localhost:9000
- Demo client: http://localhost:9001 (click "Sign in with php-sso", user `alice` / `password123`)
- Second demo client: http://localhost:9002. Sign in on one and the other signs you in without asking for a password; signing out of one ends both.
- Admin panel: http://localhost:9000/admin (user `admin` / `admin123`, or `ADMIN_PASSWORD` if set)

The first start runs `composer install` into `./vendor` (the libraries come from Packagist) and generates signing keys in
`./var/keys`. The production image is built with `docker build -f Dockerfile.production -t php-sso:local .`.
If your host user id is not 1000, set `UID` and `GID` in a `.env` file.

## Tests

```bash
docker compose exec sso vendor/bin/phpunit
```

The suite runs on SQLite. To run it against MySQL or PostgreSQL, start them and name the (empty) database it may use:

```bash
docker compose --profile databases up -d
docker compose exec -e SSO_TEST_DATABASE=mysql://sso:sso@mysql/sso_test sso vendor/bin/phpunit
docker compose exec -e SSO_TEST_DATABASE=pgsql://sso:sso@postgres/sso_test sso vendor/bin/phpunit
```

The OpenID Connect conformance suite is run as described in `conformance/README.md`.
