# php-sso

An OpenID Connect provider (OAuth 2.0 and ID tokens) with SCIM provisioning and SAML sign-in, and an admin panel. It is built
on three libraries that you can also use on their own, and laminas-diactoros:

| Package | What it is |
| --- | --- |
| [`php-sso/oauth-server`](https://github.com/php-sso/oauth-server) | The OAuth 2.0 and OpenID Connect authorization server |
| [`php-sso/scim-server`](https://github.com/php-sso/scim-server) | SCIM 2.0 provisioning of users and groups |
| [`php-sso/saml`](https://github.com/php-sso/saml) | SAML 2.0 sign-in through a company's identity provider |

This application is a demo and a starting point, not a hardened product: see [Not production ready](https://php-sso.github.io/php-sso/not-production-ready/).

## Run

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

## Documentation

Everything else is at **<https://php-sso.github.io/php-sso/>**:

- [Endpoints](https://php-sso.github.io/php-sso/endpoints/), [signing out](https://php-sso.github.io/php-sso/signing-out/) and the [admin panel](https://php-sso.github.io/php-sso/admin-panel/)
- [Signing in](https://php-sso.github.io/php-sso/sign-in/): [social](https://php-sso.github.io/php-sso/sign-in/social/), [SAML](https://php-sso.github.io/php-sso/sign-in/saml/), [SCIM](https://php-sso.github.io/php-sso/sign-in/scim/), [two-step verification](https://php-sso.github.io/php-sso/sign-in/two-step-verification/) and [sign-in protection](https://php-sso.github.io/php-sso/sign-in/protection/)
- [IP rules](https://php-sso.github.io/php-sso/ip-rules/), [theming](https://php-sso.github.io/php-sso/theming/) and [production and configuration](https://php-sso.github.io/php-sso/production/)
- [Not production ready](https://php-sso.github.io/php-sso/not-production-ready/): what the demo leaves out

The pages are in [`docs/content`](docs/content), built with Hugo and the
[php-sso docs theme](https://github.com/php-sso/docs-theme). Preview them with `hugo server --source docs`.
