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
that starts the sign-in itself) are refused. Not supported yet: signed or encrypted requests, single logout,
and signing in at the provider first.
