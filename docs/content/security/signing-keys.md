---
title: "Signing keys"
weight: 4
lead: "How tokens are signed, and how to replace the key."
---

Access tokens, ID tokens and logout tokens are signed with an RSA key. The apps check the signature with the public half, which the server
publishes at `/jwks`. The keys are in `var/keys` and are created the first time the server runs.

Replace the key on a schedule you set (once a year is common), and at once if the private key may have leaked. The `bin/keys` command does it
without signing anyone out and without breaking apps:

```bash
bin/keys list      # every key, what it does now, and until when it is published
bin/keys rotate    # add a new key
bin/keys prune     # remove what is no longer needed
```

With the Docker image, run it in the container: `docker exec sso php bin/keys rotate`.

The server also has a key for [signing SAML sign-in requests]({{< relref "sign-in/saml.md#signed-requests" >}}), made the first time a connection needs it. It is replaced
with `bin/keys saml-stage` and `bin/keys saml-activate`, which publish the new certificate before it is used.

## How a rotation works

1. **`bin/keys rotate`** makes a new key. It is published at `/jwks` right away, but the server keeps signing with the old one for an hour
   (`--delay=SECONDS` changes that). Apps cache the key list, so by the time a token with the new key reaches them, they have the key.
2. **After the delay** the new key signs. The old key signs nothing from then on, but it stays published, so tokens it signed still verify until they expire.
3. **After the retention period** (30 days; `SSO_KEY_RETENTION_DAYS`) the old key is no longer published. Run **`bin/keys prune`** from time to time:
   it removes the private half of an old key as soon as it has stopped signing, and the public half once the retention period is over.

Set the retention period to at least as long as your longest-lived token. Access and ID tokens last as long as `access_token_ttl` (an hour by default).
Refresh tokens are not signed, so a rotation does not affect them. Apps that keep an ID token to send as `id_token_hint` when signing out need the old key for
as long as they keep it.

## If a key leaked

`bin/keys rotate --now` makes the new key sign at once. Apps that cache the key list may refuse the new tokens until they refresh it (a few minutes with the
default cache time), so use it only when the old key must stop signing immediately. Then wait for the old tokens to expire and remove the old key without
waiting for the retention period by setting `SSO_KEY_RETENTION_DAYS=1`, running `bin/keys prune`, and setting it back.

Only one rotation can wait at a time: while a new key is pending, `bin/keys rotate` says so and does nothing.

## Back it up

Whoever has `var/keys` can make tokens that every app believes, so the folder is readable only by the server (`0700`, and the private keys `0600`).
[Back it up]({{< relref "backup.md" >}}) with the database (`bin/backup` includes it): without the keys, the server makes a new one, and everyone is signed out. The same folder holds `mfa.key`, which
encrypts the saved authenticator secrets; without it every one of them stops working.
