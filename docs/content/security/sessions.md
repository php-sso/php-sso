---
title: "Sessions and cookies"
weight: 2
lead: "How the browser stays signed in, and how it is protected."
---

When someone signs in, the browser gets one session cookie. It holds a random identifier and nothing else; what the session knows is kept on the server.

## The cookie

| | Over HTTP (local development) | Over HTTPS |
| --- | --- | --- |
| Name | `sso_session` | `__Host-sso_session` |
| `HttpOnly` | yes: scripts on the page cannot read it | yes |
| `SameSite` | `Lax`: other sites cannot make the browser send it with a form post | `Lax` |
| `Secure` | no | yes: the browser never sends it over plain HTTP |
| Lifetime | until the browser is closed | until the browser is closed |

Whether the server is "over HTTPS" is decided by `SSO_PUBLIC_URL`, not by how the request reached PHP, so a reverse proxy that does the TLS is fine.

The `__Host-` prefix makes browsers refuse the cookie unless it is `Secure`, has the path `/`, and was set by this exact host. A page on a sibling subdomain
(`blog.example.com` for `sso.example.com`) therefore cannot plant a session cookie of its own.

## The session

- The server only accepts a session identifier it made itself, and only from the cookie, never from the address or a form. This stops an attacker from handing
  a victim a session id they already know.
- The identifier is replaced when someone signs in, so one that was known before the sign-in is worthless after it.
- A session with no activity for an hour is signed out. That is the `session_idle_seconds` setting in `config/config.php`. Signing out, an administrator
  ending the session, and a password change end it at once.
- The session files are in `var/sessions`, readable by the server's user only (mode `0700`). They are not in the shared temporary folder, where other programs
  on the machine can read them and a cleanup job may delete them.

## When you upgrade

Over HTTPS the cookie name changed from `sso_session` to `__Host-sso_session`, so everyone who was signed in is asked to sign in once more after the
upgrade. Nothing else changes for them.

With the Docker image, `var/sessions` is inside the volume you mount at `/app/var`, so restarting or replacing the container does not sign people out.
Without that volume, it does.
