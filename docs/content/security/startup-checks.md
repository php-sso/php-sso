---
title: "Safe startup"
weight: 3
lead: "What the server refuses to start with."
---

A server that is set up in a way that weakens it is worse than one that does not start: nobody notices until it matters. So php-sso checks its settings
before it serves anyone. If one is wrong, **every page answers `500` with the reason**, the reason is written to the log, and
[`/healthz`]({{< relref "production.md#monitoring" >}}) fails, so a container orchestrator or load balancer notices too. Fix the setting and restart.

| Check | Why | What to do |
| --- | --- | --- |
| `SSO_PUBLIC_URL` is an address like `https://sso.example.com` | It is the issuer in every token, and the address people and apps use | Set it to the public address, with the scheme |
| It is `https://`, unless the host is local (`localhost`, `127.x.x.x`, `::1`, `*.localhost`) | Passwords, codes and tokens would cross the network unencrypted | Use HTTPS (a reverse proxy may do the TLS), or set `SSO_ALLOW_INSECURE_HTTP=1` if you accept that |
| `SSO_DEMO_DATA` is only used on a local address | Demo data creates people and apps with well-known passwords (`admin123`, `password123`, `demo-secret`) | Turn `SSO_DEMO_DATA` off |

## The first administrator

When the database is empty, the server creates the first administrator from `ADMIN_PASSWORD`. It refuses to do that if the password

- is missing,
- is shorter than 12 characters, or
- is made of only a few different characters repeated (`aaaaaaaaaaaa`, `abababababab`).

The message names the problem; set a longer password and start again. `ADMIN_PASSWORD` is only read while the database is empty, so it does not matter
afterwards (change the password in the admin panel). The demo data, which is for local development only, does not have this check.

## Local development

None of this gets in the way of [development]({{< relref "development.md" >}}): `docker compose` runs on `http://localhost:9000` with demo data, which
is a local address. The checks only matter once the server has a public address.
