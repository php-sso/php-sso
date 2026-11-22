---
title: "Email and password reset"
weight: 8
lead: "Let people choose a new password themselves, by email."
---

When email is set up, the sign-in page offers **Forgot your password?**. The person gives their username or email address, and if it matches an account they get an email with a link
to choose a new password. Without a mail server there is no such link and no such page: an administrator sets a new password instead.

## Turn it on

Give the server a mail server to send through, and an address to send from:

| Variable | Meaning |
| --- | --- |
| `SSO_SMTP_HOST` | The mail server. Email is off while this and `SSO_MAIL_FROM` are not both set |
| `SSO_MAIL_FROM` | The address mail is sent from, such as `sso@example.com` |
| `SSO_MAIL_FROM_NAME` | The name shown with it. Defaults to `SSO_APP_NAME` |
| `SSO_SMTP_PORT` | Default `587` (use `465` with `tls`) |
| `SSO_SMTP_ENCRYPTION` | `starttls` (default: the connection is secured after it starts), `tls` (secured from the start), or `none` |
| `SSO_SMTP_USER`, `SSO_SMTP_PASSWORD` | To sign in to the mail server, when it asks for it (PLAIN or LOGIN) |
| `SSO_SMTP_CA_FILE` | A file of certificate authorities, for a mail server whose certificate your own authority signed. By default the system's are used |
| `SSO_PASSWORD_RESET_MINUTES` | How long a link works: 60 by default, 5 to 1440 |

Then try it:

```bash
bin/mail-test you@example.com
```

It says what it connects to, sends a message, and tells you what the mail server answered, so a wrong port, a refused sign-in or a certificate that is not accepted shows up here and not when someone needs a
link. (With Docker: `docker exec sso php bin/mail-test you@example.com`.) The password is never printed.

The mail client is built in, with no library behind it. It is tested against a scripted server and against a real one (Mailpit), not against every mail service: try yours with `bin/mail-test`. Mail services that give you
an SMTP user name and password work this way; ones that only offer OAuth sign-in (XOAUTH2) are not supported.

**The connection is always checked.** With `starttls` or `tls` the certificate must be valid for the name in `SSO_SMTP_HOST`; there is no setting to skip that. A server that does not offer STARTTLS is not used without it:
the mail is not sent, and your credentials are not sent either. `none` is for a mail server on the same machine or in a private network: `localhost`, a private IP address such as `10.0.0.5`, or a name without a dot such as `mailpit`. The server refuses to start with `none` for a
public address or a public host name (a name with a dot), because a reset link in the clear is a way into an account.

Set up SPF, DKIM and DMARC for the domain you send from, as for any mail you want delivered: that is done at your DNS and mail service, not here.

## What people see

1. On the password step of signing in: **Forgot your password?**, then a form for the username or email address.
2. The same answer whatever they typed: "If an account matches, we have sent an email with a link to choose a new password."
3. An email with a button and the link, which works for an hour and once.
4. The link opens a page to choose the password (at least 8 characters, typed twice). The address in the browser is cleaned of the token as soon as the page opens.
5. They land on the sign-in page, with "Your password was changed", and sign in with the new password. Everywhere else they were signed in, they are signed out.
6. A second email tells them the password was changed, when and from where, so that someone who did not do it notices.

## What it protects against

- **Guessing who has an account.** The answer is the same for an account that exists and one that does not, the mail is sent after the answer has gone (so it does not take longer when it matches), and a
  request for an address that matches nothing is counted like the rest. Small differences in the work the database does remain; the answer and the time of it give nothing away.
- **Guessing or reading the link.** It holds 256 bits of randomness. Only a hash of it is stored, so a copy of the database holds no working link. It works once, expires, and a newer link replaces an older one.
  The token is moved out of the address as soon as the page opens, so it is not in the history or in a copied address. It does appear once in the web server's access log, where the link was opened: the log shows a link that is already used or expired.
- **Spam and pressure on one account.** An address may ask 10 times an hour, and an account is sent 3 links an hour. What is over the limit is answered like everything else and not sent.
- **Accounts that should not be reset this way.** A locked account, an account with no local password (it signs in through Google, SAML or SCIM, and a password set here would go around that) and an account with no usable
  email address are never sent a link. An email address that two people share is not used to pick one of them.
- **A reset that leaves the door open.** Using a link ends every session of the person and forgets their failed sign-ins (so a lock caused by someone else's guessing goes away). A reset is written to the
  [audit log]({{< relref "security/audit-log.md" >}}) as `account.password_reset`.

**It does not replace two-step verification.** The link proves the person has the mailbox, not their phone. If [two-step verification]({{< relref "sign-in/two-step-verification.md" >}}) is on, signing in after a reset still asks for the code. A person who also lost
their authenticator needs an administrator to reset that.

## The administrator can send the link

On a person's page, **Send reset email** sends them the link at once (and says if it could not: no mail server, a locked account, an account with no password, or what the mail server answered). The email says an administrator
sent it. It is written to the audit log as `user.send_reset`. Use it for someone who is locked out of their mailbox flow, or to give a new person a way to choose their own first password.

## The look of the emails

The emails are templates a [theme]({{< relref "theming.md#emails" >}}) can replace, in plain text and in HTML, with the subject in the text one. The pages (`password_forgot`, `password_reset`, `password_reset_invalid`) are themeable like the rest.

## When nothing arrives

- Run `bin/mail-test` first: it separates "the server cannot send" from "the mail was sent and went missing".
- A failure while sending a reset email is written to the error log (`SSO: could not send a password reset email: ...`); the person is never told, by design.
- Look in the spam folder, and check that the address of the account is right.
- A person who asked more than 3 times in an hour is not sent more until the hour has passed.

## In development

The development setup starts [Mailpit](https://mailpit.axllent.org) and points the server at it: every email lands in its inbox at <http://localhost:8025> and nothing leaves your machine. See [Development]({{< relref "development.md" >}}).
