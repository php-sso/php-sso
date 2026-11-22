# php-sso

An OpenID Connect provider (OAuth 2.0 and ID tokens) with SCIM provisioning and SAML sign-in, and an admin panel. It is built
on three libraries that you can also use on their own, and laminas-diactoros:

| Package | What it is |
| --- | --- |
| [`php-sso/oauth-server`](https://php-sso.github.io/oauth-server/) | The OAuth 2.0 and OpenID Connect authorization server |
| [`php-sso/scim-server`](https://php-sso.github.io/scim-server/) | SCIM 2.0 provisioning of users and groups |
| [`php-sso/saml`](https://php-sso.github.io/saml/) | SAML 2.0 sign-in through a company's identity provider |

This application is a demo and a starting point, not a hardened product: see [Not production ready](https://php-sso.github.io/php-sso/not-production-ready/).

## Run

There are two ways to run php-sso.

**With Docker.** The image is published for every release:

```bash
docker run -d --name sso -p 8080:8080 -v sso-data:/app/var \
  -e SSO_PUBLIC_URL=https://sso.example.com -e ADMIN_PASSWORD='choose-a-long-password' \
  ghcr.io/php-sso/php-sso:latest
```

Build your own image `FROM` it to add your theme: see [Run with Docker](https://php-sso.github.io/php-sso/docker/).

**With Composer**, on your own PHP and web server:

```bash
composer create-project --no-dev php-sso/php-sso my-sso
```

See [Install with Composer](https://php-sso.github.io/php-sso/composer/). To work on php-sso itself, `docker compose up --build` starts
a development setup with demo users and two demo apps: see [Development](https://php-sso.github.io/php-sso/development/).

## Documentation

Everything else is at **<https://php-sso.github.io/php-sso/>**:

- [Endpoints](https://php-sso.github.io/php-sso/endpoints/), [signing out](https://php-sso.github.io/php-sso/signing-out/) and the [admin panel](https://php-sso.github.io/php-sso/admin-panel/)
- [Signing in](https://php-sso.github.io/php-sso/sign-in/): [social](https://php-sso.github.io/php-sso/sign-in/social/), [SAML](https://php-sso.github.io/php-sso/sign-in/saml/), [SCIM](https://php-sso.github.io/php-sso/sign-in/scim/), [two-step verification](https://php-sso.github.io/php-sso/sign-in/two-step-verification/) and [sign-in protection](https://php-sso.github.io/php-sso/sign-in/protection/)
- [IP rules](https://php-sso.github.io/php-sso/ip-rules/), [theming](https://php-sso.github.io/php-sso/theming/) and [production and configuration](https://php-sso.github.io/php-sso/production/)
- [Not production ready](https://php-sso.github.io/php-sso/not-production-ready/): what the demo leaves out

The pages are in [`docs/content`](docs/content), built with Hugo and the
[php-sso docs theme](https://github.com/php-sso/docs-theme). Preview them with `hugo server --source docs`.
