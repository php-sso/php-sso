---
title: php-sso
lead: An OpenID Connect provider with SCIM provisioning, SAML sign-in and an admin panel, in one container.
actions:
  - name: Run it
    url: /run/
  - name: Sign-in methods
    url: /sign-in/
---

An OpenID Connect provider (OAuth 2.0 and ID tokens) with SCIM provisioning and SAML sign-in, and an admin panel. It is built
on three libraries that you can also use on their own, and laminas-diactoros:

| Package | What it is |
| --- | --- |
| [`php-sso/oauth-server`](https://github.com/php-sso/oauth-server) | The OAuth 2.0 and OpenID Connect authorization server |
| [`php-sso/scim-server`](https://github.com/php-sso/scim-server) | SCIM 2.0 provisioning of users and groups |
| [`php-sso/saml`](https://github.com/php-sso/saml) | SAML 2.0 sign-in through a company's identity provider |

This application is a demo and a starting point, not a hardened product: see [Not production ready]({{< relref "not-production-ready.md" >}}).
