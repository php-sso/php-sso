---
title: "SAML: a company's own identity provider"
weight: 2
lead: "Let staff of other companies sign in with their own login."
---

A company (a customer, or your own organisation) can have its people sign in through its identity provider
(Okta, Entra, and others) with SAML. This site is the service provider. Add a connection at
**/admin/security/saml**: the provider's entity ID, its sign-in (SSO) address, its signing certificate, and the
email domains it vouches for. The page then shows what to hand to the company's administrator: our entity ID
(`<SSO_PUBLIC_URL>/saml/<id>/metadata`, which is also the metadata address), and the reply address
(`<SSO_PUBLIC_URL>/saml/<id>/acs`). Their provider should send the person's address as the `email` attribute
(and the name as `displayName`).

**Discovery by domain:** on the sign-in page, someone who types an address at a claimed domain goes straight to
their company, with no password page. This needs people to sign in by email (`SSO_LOGIN_IDENTIFIER=email` or
`username_or_email`). A domain belongs to one connection.

**No local password for them:** a person whose address is at a claimed domain cannot sign in with a password
here (they get the same "Invalid credentials" as for a wrong one), because their company decides who they are
and whether they still work there. Switch the connection off and they can use a password again, if they have one.

A person is recognised by the provider's own id for them (the NameID), never by the email alone. A first-time
sign-in can connect to an existing account with the same verified address, or create an account (no password, no
administrator rights, no group); both are off by default and set per connection. Either only applies to an address
at one of the connection's domains: a company cannot vouch for an address at someone else's domain, and the allowed
domains of the Accounts tab apply on top. SAML is a first factor like a password: someone with two-step
verification here is still asked for a code.

What is checked, using `onelogin/php-saml`: the answer must be signed (assertion or whole message) with the
certificate you entered, and its audience, destination, issuer, time window and the schema must be right. We
only accept an answer to a request we sent, once, from the browser that sent it (a cookie set for exactly this,
`SameSite=None; Secure` over https). Over plain http, which browsers do not send that cookie back on, the binding is only
checked when the cookie is there: use https outside development. Answers nobody asked for (an identity provider
that starts the sign-in itself) are refused. Not supported yet: encrypted answers, single logout,
and signing in at the provider first.

## Signed requests

Most providers do not need them: the signed answer is the proof, and the request only says where to send the person. A few do (some
ADFS and Shibboleth setups, and providers that only answer applications they know). For those, tick **Sign our sign-in requests**
on the connection.

- The first time any connection is set to sign, the server makes a key (RSA 3072) and a self-signed certificate in `var/keys`
  (`saml-sp.key` readable only by the server, `saml-sp.crt`). One key serves every connection. The certificate is valid for ten years:
  providers read it from our metadata and do not need an authority behind it.
- A request is signed with RSA-SHA256, as the HTTP-Redirect binding says (the signature covers the query string). The **metadata** says
  `AuthnRequestsSigned="true"` for that connection and carries the certificate, and the connection's page shows the certificate and its
  fingerprint for the company's administrator. **They must trust that certificate**, or their provider will refuse our requests.
- If the key cannot be used when a request is sent, the sign-in is refused. An unsigned request is never sent to a connection that asks for signing.
- The key is part of the [backup]({{< relref "backup.md" >}}) (it is in `var/keys`) and as private as the token signing keys.

**Replacing the key** (a leak, or before the certificate expires) without a moment in which a provider refuses us:

```bash
bin/keys saml-status      # the key in use and the one staged, with fingerprints and expiry
bin/keys saml-stage       # make a new key; its certificate is published in the metadata next to the current one
# tell the companies: they add the new certificate to their provider (the metadata lists both)
bin/keys saml-activate    # sign with the new key; the old one is removed
bin/keys saml-discard     # or: throw the staged key away
```

Providers that read our metadata on a schedule pick the new certificate up by themselves; others need it by hand, so give them time
before you activate. Each step is written to the [audit log]({{< relref "security/audit-log.md" >}}).
