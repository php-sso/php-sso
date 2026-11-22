---
title: "Run it"
weight: 1
lead: "Start the demo with Docker."
---

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
