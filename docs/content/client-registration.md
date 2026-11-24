---
title: "Registering apps from code"
weight: 32
lead: "Let another app create and manage OAuth clients here (RFC 7591 and 7592)."
group: "Users and access"
---

Normally an administrator creates each app in the admin panel. With **dynamic client registration**, a trusted app does it
by sending a small JSON message, and gets a client id back. The typical case: your own product lets its users connect
tools (an MCP client, a script) and needs a client here for each connection, without anyone opening the admin panel.

It is **off by default**. A registration is only accepted from a caller that holds an access token with the `client:register` scope.

## Let a client register apps

Registration is always there, but only a client you allow can use it. Open the client in the admin panel (a confidential client with the
`client_credentials` grant), go to its **Registration** tab, tick **May register apps**, and choose the scopes the apps it registers may get.

That is the whole setup. The client can now ask for the `client:register` scope, `POST /register` works for it, and the discovery
document lists `registration_endpoint` (it is left out while no client may register). Untick the box and it stops at once, even for a token
the client already holds. The `client:register` scope is built in: it does not appear under **Scopes**, and no list of scopes can hand it out.

## Register an app

Your registrar asks for a token and registers a client:

```bash
TOKEN=$(curl -s -u registrar:SECRET -d grant_type=client_credentials -d scope=client:register \
  https://sso.example.com/token | jq -r .access_token)

curl -s -X POST https://sso.example.com/register \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"redirect_uris": ["http://127.0.0.1:8123/callback"], "token_endpoint_auth_method": "none",
       "client_name": "My MCP client", "grant_types": ["authorization_code", "refresh_token"]}'
```

The answer (201) holds `client_id`, `registration_access_token`, `registration_client_uri` and, for an app that authenticates with a
secret (`client_secret_basic` or `client_secret_post`, the default), a `client_secret`. **Keep the secret and the registration
access token: they are shown once** and only their hashes are stored.

There are two kinds of token. The `client:register` token may create clients. A registration access token may manage exactly one client:
`GET` reads it, `PUT` replaces it (send the whole description and its `client_id`), `DELETE` removes it (204).

## What is accepted

- **Redirect URIs** are exact, `https`, or `http` for `localhost`, `127.0.0.1` and `[::1]` only. Private-use schemes such as `com.example.app:/cb`
  are fine for native apps. `javascript:`, `data:` and `file:` never are.
- **Grants:** `authorization_code` and `refresh_token`. Client credentials is refused, so a self-registered app never gets a machine identity.
- **Scopes:** the ones ticked on the registrar's Registration tab (the built-in ones to begin with). An app that names none gets `openid`.
- **Resources** (RFC 8707): the ones on the registrar's Registration tab. See [Tokens for one API]({{< relref "resource-indicators.md" >}}).
- **PKCE** is required of public apps as always. Informational URLs (`client_uri`, `logo_uri`, `tos_uri`, `policy_uri`) must be `https`. Software statements are not supported.

## What you get in the admin panel

A registered app is an ordinary client: it appears in the list under its `client_name` (or "Registered client" and the start of its id) with a note that it was registered through the API, its Registration tab says which client registered it and when, and you can
restrict it to chosen people or groups, change it or delete it. **A new client is open to every person, like one you create yourself**, so restrict it if it
should not be. Deleting it, here or through the API, stops its codes and refresh tokens; access tokens already issued live until they expire.

## Good to know

- Limit who can reach `/register` like the token endpoint: the [IP rules]({{< relref "ip-rules.md" >}}) apply to registering, and put a rate limit in front of it.
- `client_name` and the other descriptive fields come from whoever registered the app. They are escaped wherever this server shows them.
- A `GET` of a registration does not repeat the secret or the registration access token, because they are not stored.
