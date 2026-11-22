---
title: "Security"
weight: 22
lead: "What php-sso does to protect people and data, page by page."
group: "Run in production"
---

This section describes what the server does by itself, and what it expects of you. Where something is a setting, the page says which.

| Page | What it covers |
| --- | --- |
| [Security headers]({{< relref "security/headers.md" >}}) | The headers every response carries, the default `Content-Security-Policy`, and how to change it for a theme |
| [Threat model and checks]({{< relref "security/threat-model.md" >}}) | Who could attack, what stops them, what is still open, and the checks that can be run again |
| [Sessions and cookies]({{< relref "security/sessions.md" >}}) | The browser session cookie, where its files live, and how long a session lasts |
| [Signing keys]({{< relref "security/signing-keys.md" >}}) | How tokens are signed, rotating the key, and what to back up |
| [Audit log]({{< relref "security/audit-log.md" >}}) | Who changed what and when, what is recorded, how long it is kept, and sending it elsewhere |
| [Safe startup]({{< relref "security/startup-checks.md" >}}) | What the server refuses to start with, and why: plain HTTP, demo data, a weak first password |

Related, described where they belong:

- [Sign-in protection]({{< relref "sign-in/protection.md" >}}): limits on failed attempts, for people and for apps
- [Email and password reset]({{< relref "sign-in/password-reset.md" >}}): choosing a new password by emailed link, and what protects it
- [Two-step verification]({{< relref "sign-in/two-step-verification.md" >}}) and [required steps]({{< relref "sign-in/required-steps.md" >}})
- [IP rules]({{< relref "ip-rules.md" >}}): which network addresses may sign in

## What is stored, and how

- **Passwords** are stored as a salted hash made with PHP's `password_hash`, never as text. Client secrets are stored the same way.
- **Authorization codes, refresh tokens and SCIM tokens** are stored as hashes. A copy of the database does not give anyone a token, and a SCIM
  token is shown once, when it is made.
- **Authenticator secrets** (two-step verification) are encrypted with AES-256-GCM before they go into the database. The key is the file
  `var/keys/mfa.key`: back it up with the rest of `var/keys`, because without it every saved authenticator stops working.
- **Signing keys** for tokens are in `var/keys` too. Whoever has them can make tokens that every app will believe, so keep that folder
  readable only by the server. [Rotate them]({{< relref "security/signing-keys.md" >}}) with `bin/keys`.

## What is not there yet

- The [audit log]({{< relref "security/audit-log.md" >}}) is in the same database as the data it describes, so it is not proof against someone who can change the database: stream it elsewhere for that. See also [Not production ready]({{< relref "not-production-ready.md" >}}).
- php-sso has not had an independent security review. The code and the tests are public, and the Basic OP plan of the OpenID Connect conformance
  suite can be run against it (`conformance/README.md` says what has been run), but neither is an independent review.

## Reporting a problem

Please report a vulnerability privately, through GitHub's "Report a vulnerability" button on the
[repository's Security tab](https://github.com/php-sso/php-sso/security). The policy, with what to expect and when, is in
[`SECURITY.md`](https://github.com/php-sso/php-sso/blob/master/SECURITY.md).
