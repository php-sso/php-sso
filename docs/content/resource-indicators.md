---
title: "Tokens for one API"
weight: 35
lead: "Make an access token valid for a single API (RFC 8707 resource indicators)."
group: "Users and access"
---

By default an access token is for this server and for the app it was issued to. If one login server protects several APIs, you
want a token that only one API accepts. The app says which API it wants by sending the API's address as `resource`, and the token
is only good there. MCP servers expect this of their clients.

## Allow an app a resource

Open the app in the admin panel, and on its **Settings** tab fill in **Allowed resources** under **APIs**: one absolute URI per line, exactly as
the app will send it (`https://api.example.com/`). An app can only get tokens for resources on its list. Without a list, the `resource`
parameter is refused with `invalid_target`, and tokens stay as they were.

For apps that [register themselves]({{< relref "client-registration.md" >}}), you set the resources on the **registrar's** Registration tab:
every app it registers may get tokens for those.

## What the app does

Add `resource` to the authorization request, and to the token request when it wants to narrow it:

```
GET /authorize?response_type=code&client_id=...&resource=https%3A%2F%2Fapi.example.com%2F&...
POST /token   grant_type=authorization_code&code=...&resource=https%3A%2F%2Fapi.example.com%2F
```

- The access token's `aud` is the resource followed by this server's own address (so `/userinfo` still works). It no longer names the app.
  The API accepts the token only when its own address is in `aud`.
- `resource` may appear several times. A token request may name some of what the authorization named, never more. A refresh may narrow
  too, and the next refresh can still ask for any of the originals.
- A resource the app may not have, or that is not an absolute URI without a fragment, is an `invalid_target` error. Taking a resource off the list
  stops refreshes for it at once.
- ID tokens are unchanged. The device authorization request does not take `resource`; the token request of the device grant does.
