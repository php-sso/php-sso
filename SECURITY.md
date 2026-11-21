# Security policy

## Reporting a vulnerability

Please do not open a public issue for a security problem. Report it privately through
GitHub's "Report a vulnerability" button on the Security tab of this repository
(private vulnerability reporting). Include what you found, how to reproduce it, and which version.

What to expect:

- An acknowledgement within 3 working days.
- A first assessment within 10 working days.
- A fix and a coordinated disclosure date agreed with you. The default is 90 days at most.
- Credit in the release notes, unless you prefer to stay anonymous.

Problems in the libraries themselves belong in their repositories:
[php-sso/oauth-server](https://github.com/php-sso/oauth-server),
[php-sso/scim-server](https://github.com/php-sso/scim-server) and
[php-sso/saml](https://github.com/php-sso/saml).

## Supported versions

Only the latest release receives security fixes. Before 1.0, that means the newest 0.x release.

## Scope

In scope: the code in `src/`, `public/`, `templates/` and `config/` (sign-in, sessions, the admin panel, the account pages,
two-step verification, social and SAML sign-in, SCIM provisioning, IP rules).

Out of scope: the demo values below, which are public on purpose, and the demo client in `client/`.

## Demo defaults that are public

This project is a demo and a starting point, and the README lists what is missing for production ("Not production ready"). The
following are well known and must never be used on a real installation:

- The seed credentials: the administrator `admin` / `admin123`, the user `alice` / `password123`, and the demo client secrets.
  They are only created when `SSO_DEMO_DATA=1`. Without it, only the administrator is created and `ADMIN_PASSWORD` must be set.
- `SSO_DEBUG=1`, which shows theme errors instead of falling back.
- PHP's built-in web server and plain `http://`, as in `docker-compose.yml`. The production image (`Dockerfile.production`) runs
  Apache on port 8080 and expects a reverse proxy that terminates HTTPS.

## What you are responsible for

- Serve the issuer over HTTPS only, and set `SSO_TRUSTED_PROXIES` to your reverse proxies so IP rules and throttling see real addresses.
- Back up `var/` (the database and the signing and two-step keys) and keep it readable by the server only.
- Rate-limit the sign-in and token endpoints in front of the application.
