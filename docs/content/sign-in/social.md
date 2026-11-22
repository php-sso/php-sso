---
title: "Social sign-in"
weight: 1
lead: "Google, GitHub and any OpenID Connect provider."
---

People can sign in with an account at another provider: Google, GitHub, or any OpenID Connect provider. Set the
client id and secret (see the environment variables below) and register
`<SSO_PUBLIC_URL>/login/social/<provider>/callback` at the provider (`google`, `github` or `oidc`; the
exact address is shown at **/admin/security**). More providers go in `login.social.providers` in
`config/config.php`, as `oidc` (needs an `issuer`) or `github`. Provider addresses must be https.

A provider account is recognised by the provider's own permanent id for the person, never by the email alone.
It has to be connected to a person first, in one of three ways:

- **They connect it themselves** on their account page while signed in (and can disconnect it again, unless
  it is their only way to sign in).
- **By verified email** (off by default): the provider vouches for the address and so do we, and the person
  has no other account at that provider.
- **By creating an account** on first sign-in (off by default): no password, no administrator rights, no group.

The last two trust what a provider says about an email address, so they are switched on at
**/admin/security** (Social sign-in) and restricted by email domain on the Accounts tab. Signing in with a provider is a first factor like a password: someone
with two-step verification is still asked for a code, and the policy still makes people set it up.

Each round trip is bound to the browser that started it (state, nonce and PKCE verifier, used once), and ID
tokens are only believed after their RS256 signature, issuer, audience, expiry and nonce check out. The
provider's discovery document and keys are kept for an hour, so most sign-ins make no outside request. A token
signed with a key we do not know (the provider changed its keys) makes us look again, but at most once every five
minutes. Answers from providers are read in pieces and refused when they are larger than 1 MB or take too long.
