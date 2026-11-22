---
title: "Endpoints"
weight: 4
lead: "The addresses the server answers on."
---

| Endpoint | Purpose |
| --- | --- |
| `GET /.well-known/openid-configuration` | Discovery document |
| `GET /jwks` | Public key (JWKS) for verifying ID tokens |
| `GET /authorize` | Authorization endpoint (code flow, PKCE `S256` required for every client, `nonce`) |
| `POST /token` | `authorization_code`, `refresh_token`, `client_credentials` and device code grants (refresh tokens rotate; reusing an old one revokes the whole chain); client auth via Basic or POST body |
| `POST /device_authorization` | Starts a device flow (RFC 8628): returns a device code and a short user code |
| `GET`/`POST /device` | The page where a person enters the code and approves the device |
| `POST /revoke` | Token revocation (RFC 7009) |
| `POST /introspect` | Token introspection (RFC 7662), for confidential clients such as APIs |
| `GET /userinfo` | Claims for a Bearer access token (needs the `openid` scope) |
| `GET`/`POST /end-session` | RP-initiated logout (`id_token_hint`, `post_logout_redirect_uri`, `state`) |
| `GET /login`, `/logout` | SSO session on this server |
| `GET /.well-known/oauth-authorization-server` | Same metadata as the discovery document (RFC 8414) |

Scopes: `openid`, `profile` (name), `email` (email, email_verified), `groups` (names of the user's groups), `roles` (names of the user's roles, see below).
Each client lists the scopes it may ask for. Add more scopes (a name without claims, such as `invoices:read`) under **Scopes** in the admin panel.

Grants (set per client, on its Settings tab): `authorization_code` (PKCE required), `refresh_token`,
`client_credentials` (confidential clients only; the token's subject is the client) and the device code grant.
The password grant and the implicit flow are not offered. `prompt=login`, `prompt=none` and `max_age` are
supported, and ID tokens carry `auth_time`.

**Device flow:** a TV or CLI posts to `/device_authorization` and shows the user code. The person opens
`/device` on their phone, signs in, enters the code and approves. Wrong codes are limited to 10 per person
per 10 minutes. The page is themeable (`device.html.twig`).
