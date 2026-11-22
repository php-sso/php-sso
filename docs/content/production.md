---
title: "Production and configuration"
weight: 7
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
| `SSO_THEME_DIR` | Theme folder, default `/theme` |
| `SSO_DEBUG` | `1` shows theme errors instead of falling back |
| `SSO_TRUSTED_PROXIES` | Comma separated addresses or CIDR ranges of your reverse proxies. Only their `X-Forwarded-For` is believed; set it when behind a proxy, or IP rules see the proxy's address |
| `SSO_PKCE_OPTIONAL_FOR_CONFIDENTIAL_CLIENTS` | `1` stops requiring PKCE of confidential clients. Public clients always need it. It exists so the OpenID conformance suite can run (see `conformance/README.md`); leave it off in production |
| `SSO_DEMO_DATA` | `1` seeds a demo user, group and client with known passwords (development only) |
