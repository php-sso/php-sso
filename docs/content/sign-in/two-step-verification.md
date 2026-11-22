---
title: "Two-step verification"
weight: 4
lead: "The authenticator app and recovery codes."
---

A person can add an authenticator app (any TOTP app: Google or Microsoft Authenticator, Authy, 1Password,
and so on) on their account page. They get 8 single-use recovery codes, shown once. From then on a right
password is not enough: they are asked for a 6-digit code (or a recovery code) before they are signed in.
Each code works once, and the code step is subject to the same limits as passwords (see below).

The policy decides who *must* have it: nobody (`optional`), administrators, or everyone. Whoever is
required and has not set it up yet is taken through setup at their next sign-in, before they get in.
Anyone who has set it up is always asked, whatever the policy. Tightening the policy does not sign anyone
out; it applies at the next sign-in.

If someone loses their phone and their recovery codes, an administrator resets it on their user page. That
removes the app and codes and signs them out everywhere; they set it up again at their next sign-in.

The authenticator secrets are stored encrypted (AES-256-GCM) with a key in `var/keys/mfa.key`, created on
first use. Back it up with the rest of `var/keys` and never commit it: without it, no stored secret can be
read and every person who set it up needs a reset.

Setup shows a QR code to scan (drawn on the server, as SVG, so the secret never goes to an outside service),
with the key to type in and an `otpauth://` link as fallbacks.

**What apps are told.** The ID token carries an `amr` claim (RFC 8176): how the person signed in, so an app can ask for
a second factor. It is a list, for example:

| Signed in with | `amr` |
| --- | --- |
| a password | `["pwd"]` |
| a password and a code from the authenticator app | `["pwd", "otp", "mfa"]` |
| a password and a recovery code | `["pwd", "mfa"]` |
| an outside account (social or SAML) | `["fed"]` |
| an outside account and a code from the app | `["fed", "otp", "mfa"]` |

Setting the app up while signing in counts as having used it. `fed` is not one of the names RFC 8176 registers: nothing
there says "an outside provider vouched for this person", and we cannot tell how that provider checked them, so we do not
claim `pwd` for it. An app that requires a second factor looks for `mfa`. It stays the same through refreshes, is not in
the access token, and is left out for sessions that began before this existed and for an administrator browsing as someone
else. It describes what this server checked: a company's own two-step verification at its identity provider is not
visible here.
