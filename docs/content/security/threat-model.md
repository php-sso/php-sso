---
title: "Threat model and checks"
weight: 6
lead: "Who could attack php-sso, how, what stops them, and what has been checked."
---

This page is the author's own analysis, not an independent review. It says what is protected, who might attack it, what stands in the way, and what is left over. The checks listed at the end can be run again by anyone.

## What is protected

- **Passwords** (stored as hashes), **client secrets** (hashes), **signing keys** and the **two-step secrets** (encrypted) in `var/keys`.
- **Sessions, authorization codes and tokens**: whoever holds one acts as that person.
- **The admin panel**: whoever reaches it controls every account.
- **The audit log**: the record of who did what.

## Who might attack

| Attacker | Wants | Can |
| --- | --- | --- |
| Anyone on the internet | An account, a list of accounts, or the server down | Send any request to the public pages and endpoints |
| A person with an account | More access than they have | Sign in, use the account pages, call endpoints with their own tokens |
| A malicious website | To act as a visitor of the server | Make the visitor's browser send requests, or open a page that embeds the server's |
| A malicious or broken app (client) | Other people's data, or a wider scope than it was given | Hold a client secret and call `/authorize`, `/token`, `/userinfo` |
| Someone who gets a copy of the database or a backup | Passwords and working credentials | Read every row |
| Someone who reads the logs or the mail in transit | Links and tokens | See what is written there |

An attacker who controls the server itself, the host, or an administrator's browser is out of scope: nothing here can protect against them.

## Threats and what answers them

| Threat | Answer | Where |
| --- | --- | --- |
| Guessing passwords | Per person and per address limits, locks, equal answers for known and unknown names | [Sign-in protection]({{< relref "sign-in/protection.md" >}}) |
| Guessing a client secret | Failures are counted per client and per address; the address gets `429` | [Sign-in protection]({{< relref "sign-in/protection.md" >}}) |
| Stealing a stored credential from a copy of the database | Passwords and client secrets are hashed, reset tokens and refresh tokens are stored as hashes, two-step secrets are encrypted | [Signing keys]({{< relref "security/signing-keys.md" >}}) |
| Reusing a code or a token | Authorization codes and refresh tokens work once, also when requests race | the load and race test below |
| Sending the code to another address | Redirect addresses must match the registered ones exactly; a wrong one gets an error page, never a redirect | conformance run |
| Intercepting a code | PKCE is required of everyone by default | [Production]({{< relref "production.md" >}}) |
| Forged requests from another website | A token in every form, checked for all `POST` requests of the admin, account and sign-in pages; `SameSite=Lax` cookies | [Sessions and cookies]({{< relref "security/sessions.md" >}}) |
| Clickjacking and script injection | `frame-ancestors 'none'`, a strict Content-Security-Policy, output escaped by default | [Security headers]({{< relref "security/headers.md" >}}) |
| Fixing a session id before sign-in | A new session id is issued at sign-in | probe below |
| Reaching the admin panel or files | Admin routes need a signed-in administrator; only `public/` is served; `var`, `vendor` and `config` answer `404` | probe below |
| A forged `Host` header changing links | Links and the issuer come from `SSO_PUBLIC_URL`, never from the request | probe below |
| Learning who has an account | The same answer and the same work for reset requests and sign-in, whether the account exists or not | [Email and password reset]({{< relref "sign-in/password-reset.md" >}}) |
| Taking over an account by reset | Links are single use, short lived, limited, and sent only to accounts that are not locked and have a local password; two-step still applies | [Email and password reset]({{< relref "sign-in/password-reset.md" >}}) |
| Weak settings in production | The server refuses to start with a public HTTP address, a weak first admin password, or unsafe mail settings | [Startup checks]({{< relref "security/startup-checks.md" >}}) |
| Denying what happened | Every administrative action and sign-in event is written to the audit log, which people with admin access cannot edit from the panel | [Audit log]({{< relref "security/audit-log.md" >}}) |
| Losing data | `bin/backup` and `bin/restore`, which are tested on all three databases | [Operations]({{< relref "production.md" >}}) |

## Known gaps

These are known, and not fixed yet:

- **`GET /logout` has no token.** A page on another website can send a visitor there and sign them out (and notify the apps that they were signed out). This is a nuisance and gives no access. Making sign-out a `POST` would change the links apps use.
- **No independent review.** Everything on this page is the author's analysis. An outside review, ideally including testing of a running instance, is still needed before relying on php-sso for something that matters.
- **No rate limit in front of the application.** Limits are in the application. Flooding the server with requests is for your reverse proxy or firewall to handle.
- **PHP session files** are cleaned by PHP's own collector, not by php-sso.
- **Linking a social account** to an existing person is not written to the audit log.
- **Mail:** a failed reset email is logged, not retried, and the person is not told. The built-in SMTP client has been tested against a scripted server and Mailpit, not every provider, and has not been run against a live TLS server in the project's own tests.
- **Timing.** Equal answers for known and unknown accounts are kept equal in work and in what is sent, but small differences in database work remain.

## Checks you can run again

| Check | What it shows | How |
| --- | --- | --- |
| Unit and integration tests (about 1,200) on SQLite, MySQL and PostgreSQL | Behaviour and the schema agree on all three | `vendor/bin/phpunit`, see [Development]({{< relref "development.md" >}}) |
| OpenID conformance suite, Basic OP plan | The server follows the specification. The last run: 36 modules, 0 failures, 6 warnings and 4 skips that are the same as in earlier runs (an optional scope and a request object feature that are not claimed) | `conformance/run.sh up`, `run`, `down` |
| Upgrade from a release | A database made by the published version, with people, a session and tokens, still works with this version: old tokens, keys, password hashes and sign-in | `tests/upgrade/run.sh v0.1.0` |
| Load and race test | Throughput and latency of the endpoints apps call, and that one code or refresh token works once when 20 requests race | `tests/load/run.py http://localhost:8080` against a test instance |
| Probes of a running server | No access without signing in, no file traversal, no redirect to an unregistered address, headers present, session id changes at sign-in, a wrong form token is refused | repeated by hand with `curl`; the probes are not a script yet |

The load test is a single process on a development machine, so its numbers (about 600 requests a second at 20 clients, 99% under 100 ms, for the production image with SQLite) say that nothing is slow or broken at that level, not how much a production server will carry. Measure your own setup.
