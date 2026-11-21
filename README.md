# php-sso

An OpenID Connect provider (OAuth 2.0 and ID tokens) with SCIM provisioning and SAML sign-in, and an admin panel. It is built
on three libraries that you can also use on their own, and laminas-diactoros:

| Package | What it is |
| --- | --- |
| [`php-sso/oauth-server`](https://github.com/php-sso/oauth-server) | The OAuth 2.0 and OpenID Connect authorization server |
| [`php-sso/scim-server`](https://github.com/php-sso/scim-server) | SCIM 2.0 provisioning of users and groups |
| [`php-sso/saml`](https://github.com/php-sso/saml) | SAML 2.0 sign-in through a company's identity provider |

This application is a demo and a starting point, not a hardened product: see [Not production ready](#not-production-ready).

## Run

```bash
docker compose up --build
```

- SSO server: http://localhost:9000
- Demo client: http://localhost:9001 (click "Sign in with php-sso", user `alice` / `password123`)
- Second demo client: http://localhost:9002. Sign in on one and the other signs you in without asking for a password; signing out of one ends both.
- Admin panel: http://localhost:9000/admin (user `admin` / `admin123`, or `ADMIN_PASSWORD` if set)

The first start runs `composer install` into `./vendor` (the libraries come from Packagist) and generates signing keys in
`./var/keys`. The production image is built with `docker build -f Dockerfile.production -t php-sso:local .`.
If your host user id is not 1000, set `UID` and `GID` in a `.env` file.

## Endpoints

| Endpoint | Purpose |
| --- | --- |
| `GET /.well-known/openid-configuration` | Discovery document |
| `GET /jwks` | Public key (JWKS) for verifying ID tokens |
| `GET /authorize` | Authorization endpoint (code flow, PKCE `S256` required for every client, `nonce`) |
| `POST /token` | `authorization_code`, `refresh_token`, `client_credentials` and device code grants (refresh tokens rotate; reusing an old one revokes the whole chain); client auth via Basic or POST body |
| `POST /device_authorization` | Starts a device flow (RFC 8628): returns a device code and a short user code |
| `GET`/`POST /device` | The page where a person enters the code and approves the device |
| `POST /revoke` | Token revocation (RFC 7009) |
| `POST /introspect` | Token introspection (RFC 7662), for confidential clients such as APIs |
| `GET /userinfo` | Claims for a Bearer access token (needs the `openid` scope) |
| `GET`/`POST /end-session` | RP-initiated logout (`id_token_hint`, `post_logout_redirect_uri`, `state`) |
| `GET /login`, `/logout` | SSO session on this server |
| `GET /.well-known/oauth-authorization-server` | Same metadata as the discovery document (RFC 8414) |

Scopes: `openid`, `profile` (name), `email` (email, email_verified), `groups` (names of the user's groups), `roles` (names of the user's roles, see below).
Each client lists the scopes it may ask for. Add more scopes (a name without claims, such as `invoices:read`) under **Scopes** in the admin panel.

Grants (set per client, on its Settings tab): `authorization_code` (PKCE required), `refresh_token`,
`client_credentials` (confidential clients only; the token's subject is the client) and the device code grant.
The password grant and the implicit flow are not offered. `prompt=login`, `prompt=none` and `max_age` are
supported, and ID tokens carry `auth_time`.

**Device flow:** a TV or CLI posts to `/device_authorization` and shows the user code. The person opens
`/device` on their phone, signs in, enters the code and approves. Wrong codes are limited to 10 per person
per 10 minutes. The page is themeable (`device.html.twig`).

## Signing out

- **Ending a session** (by the person, by an administrator, on password change, or when the user is
  deleted) revokes the auth codes, access tokens and refresh tokens issued during it. Refreshing and
  `/userinfo` stop working at once. Idle expiry alone does not revoke anything.
- **Back-channel logout:** give a client a *Back-channel logout URL* and it gets a signed logout token
  (`sid` and `sub` claims) by POST whenever a session it took part in ends. ID tokens carry the matching
  `sid`. Delivery is attempted once, with a 3 second timeout, by the OAuth server's logout service;
  failures are returned to us and logged.
- **Single logout from an app:** the app sends the browser to `/end-session` with `id_token_hint`. Register
  its *Post-logout redirect URIs* in the client to let it come back. Without a hint, the person is asked
  to confirm first.
- A refresh also checks that the person still exists and is still allowed to use the client (group access).
- What cannot be undone: an access token or ID token that is already out is a signed JWT and stays valid
  until it expires (1 hour by default), and apps that do not support back-channel logout keep their own session.

## Admin panel

Reachable at `/admin` for users with the administrator flag; it reuses the SSO login. Manage:

- **Users:** username, name, email, password, admin flag. Each user has a permanent `sub` (UUID), so
  renaming a user never changes their identity in tokens.
- **Groups:** exposed to apps through the `groups` claim.
- **Roles:** names an app can check, such as `editor` or `admin`. A role is defined once under **Roles**, given to groups
  and to single users once, and made available in *every client* or only in the clients you pick. An app that asks for
  the `roles` scope receives the roles its user has that are available in it, as the `roles` claim. The server
  does not know what a role allows: the app decides. A role is the same in every app that receives it; for "editor in
  one app, viewer in another" use two role names.
- **Clients:** redirect URIs, allowed grants, confidential or public. The secret of a confidential
  client is shown once on creation and stored hashed; you can generate a new one at any time.
- **Scopes:** what an app can ask for. The built-in ones (`openid`, `profile`, `email`, `groups`, `roles`) release facts about
  the person and are set up in `config/config.php`, because the code behind them is fixed. Under **Scopes** you add
  more, such as `invoices:read`: they release no claims, they are only a name that goes into the access token, and the app or
  service that receives the token decides what it means (for example by requiring it with
  `bearerTokenMiddleware(['invoices:read'])` from `php-sso/oauth-server`). Names use letters, digits and `. _ : -`
  and are case-insensitive. A new scope is allowed for **no** app until you tick it on that app's page, and an app saved
  without a scope list of its own may ask for the built-in scopes only. You cannot remove a scope an app may still ask for. Taking a scope
  away from an app also takes it off refresh tokens already issued: their next refresh carries only what is still allowed, though
  access tokens already issued keep it until they expire (an hour).
- **Who can sign in to a client:** *Everyone*, or *Only selected groups and people*. A restricted client
  accepts the members of its groups plus the people added by name; with nobody added, nobody gets in.
  People without access see a "No access" page, and a refresh token stops working once access is lost.

Links between people, groups and clients (members of a group, groups of a person, who may use a client)
are all edited with the same control: search to add, a paged and filterable list, remove, or remove
everything matching the filter. It searches on the server,
so it stays fast with thousands of people, and every change applies immediately.

Everything lives in `var/sso.sqlite` (the `storage` setting in `config/config.php`; the app only talks to the `PhpSso\Store\Storage` interface, so another backend is another implementation of it, chosen there). `bin/maintenance` removes expired data and can run from cron. On first start, an empty database is seeded from the `seed`
section of `config/config.php`; after that the config is no longer read for users or clients.
Delete `var/sso.sqlite` to start over.

The panel's stylesheet and script are in `public/assets/`. The fonts there (Bricolage Grotesque and
Instrument Sans) are bundled so the panel makes no third-party requests; both are licensed under the
SIL Open Font License.

## Signing in

Signing in takes two steps: the person says who they are (`/login`), then proves it with a password
(`/login/password`). The first step answers the same whether or not the account exists, so it does not
reveal who has one. What people type is set with `SSO_LOGIN_IDENTIFIER` (`username`, `email` or
`username_or_email`). The email modes need a different email address for every user, so the server
refuses to start while two users share one, and the admin forms reject duplicates. With
`username_or_email`, new usernames cannot contain `@`.

A user can exist without a local password (for example when provisioned from elsewhere); such a person
cannot sign in with a password.

### Social sign-in

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

### SAML: a company's own identity provider

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

### SCIM: provisioning from a company's system

A company's system (Okta, Entra, or another) can create, update and deactivate its people here with SCIM 2.0, at
`<SSO_PUBLIC_URL>/scim/v2`. This is separate from SAML: it works whatever way those people sign in. Add a client at
**/admin/security/scim** with a name and the email domains it may manage, and give its token to the provider as a bearer
token. The token is shown once and only its hash is kept; make a new one if it is lost or exposed.

Users and groups: `POST`, `GET` (with `filter=userName eq "..."`, which providers use to look people up, and
also `externalId`, `id` and `emails.value`; for groups `displayName`, `externalId` and `id`), `PUT`, `PATCH` and `DELETE`,
plus `ServiceProviderConfig`, `ResourceTypes` and `Schemas`. A person needs a `userName` and an email address in `emails`.

- **Scope.** A client only creates and changes people whose email is at its domains, only manages people it created
  (or took over, see below), and never touches an administrator.
- **Allowed email domains.** The server-wide list (Security, Accounts) also applies: a client cannot create a person, or move
  someone's address, to a domain outside it (`400 invalidValue`). It does not stop a client taking over an account that exists, or
  changing someone whose address stays as it is.
- **No password.** People it creates have no password, no administrator rights and no group. They sign in another way
  (social, SAML, or a password you give them). A `password` sent by the provider is ignored.
- **Deactivating** (`active: false`) locks the account and ends its sessions at once. Reactivating lets the person back in.
- **Delete** is the client's setting: by default it deactivates (the record and what apps know of the person stay),
  or it can delete the account for good.
- **Taking over** (off by default): with it on, provisioning someone whose address already belongs to an account made some
  other way (not an administrator, not managed by another client) takes that account over instead of refusing.
- **Overwritten.** A name or email changed by hand for a provisioned person is overwritten at the next sync, and an
  administrator who locks such a person can be undone by the provider. The person's page says it is provisioned.
- Every request is recorded on the client's page (when, what, result). SCIM calls carry no cookie and start no session.

**Groups.** Group names reach apps in the `groups` claim, and roles are given to groups, so they get extra care:

- A client only manages groups it created, and can only put people it manages in them (anyone else is refused, and
  the whole request changes nothing).
- A group whose name already exists is refused (409), so a provider cannot take over one of yours. The client's
  "Take over existing groups with the same name" switch (off by default) allows it, and the group's members are then
  replaced by the provider's. Another client's group is never taken over.
- The group's name for apps is the client's optional **prefix** plus the name it sent, with every run of characters
  other than letters, digits and `_ . : -` turned into a dash (`All Employees` becomes `All-Employees`, or
  `acme:All-Employees` with the prefix `acme:`). It must come to 1 to 64 characters. The provider keeps seeing its own
  name, and renaming a group changes what apps see.
- `PATCH` handles adding, removing (also `members[value eq "id"]`, as Entra sends it) and replacing members, and `PUT`
  replaces the whole group. Deleting a group deletes it, with its memberships and the roles given to it.
  Deactivating a person leaves their groups alone.
- A group's page says it is provisioned, and changes made to it by hand are overwritten at the next sync.

Not supported yet: bulk, sorting, ETags, filters other than `eq`, and pushing people out to apps.

### Two-step verification

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

### Steps required before signing in

After someone has proved who they are (and passed the second factor, when there is one), the server can still
require something before it creates their session. Today there is one: **choose a new password**. Tick
"Must choose a new password at next sign-in" on the user form (it is on by default for new users, since you chose
their first password). They are asked at `/login/required` before they can continue, and their other sessions
end when they have chosen one. A person who is already signed in when you tick it is stopped at their next visit
to the account page, the admin panel or an app's sign-in, and sent through the step first. People without a local
password (social sign-in) are never asked.

### Notices people must accept

Under Sign-in protection, "Notices", you can require people to accept something (terms of use, an acceptable use
policy) before they can sign in. A notice is a name, a short plain-text message and a link to the full text, which
stays on your own site: the SSO does not host or edit that content. Several notices can be switched on, and people
accept them one page at a time, before any password change. They are held at the next sign-in, and also when they
are already signed in and visit the account page, the admin panel or an app's sign-in. That includes you: add a
notice and you accept it yourself first.

Each notice has a version number that starts at 1. Editing the message or link does **not** ask anyone again, so a
typo costs nothing. Use "Ask everyone again" after the text on your site changed in a way people should agree to:
it adds 1 to the version. On a person's page, "Ask again" does the same for one person. Every acceptance records
who, which version, when and from which address, plus a copy of the message and link shown at that moment. A notice
that someone accepted cannot be deleted, so the record stays: switch it off instead.

### Sign-in protection

Too many failed passwords within a time window lock sign-in, for one person (the account, or the
identifier typed when there is no such account) or for one IP address. Defaults are 5 per person and 20
per address within 15 minutes, set in `config/config.php` (`login.throttle`).

Apps that get their client secret wrong are slowed down too: 10 failures for one client from one address, or 30
from one address for any client, within 15 minutes, and that address gets `429` with `Retry-After` from `/token`,
`/introspect`, `/revoke` and `/device_authorization`. Counting per client *and* address means nobody can lock an
app out by naming its client id. Set in `config/config.php` (`client_auth_throttle`); behind a reverse proxy,
set `SSO_TRUSTED_PROXIES` or every request looks like it comes from the proxy.

Administrators manage it at **/admin/security**, which has tabs for Activity, Limits, Two-step verification,
Social sign-in and IP rules:

- change the limits and the window, or turn limiting off (what is saved there wins over the config), and
  set the two-step verification policy;
- see everyone with failed attempts right now, who is locked, and when it clears;
- **reset** a person, identifier or address so they can try again at once (also available on a user's
  page). The failed attempts stay in the sign-in history and simply stop counting;
- see recent lockouts. Refusals are shown once per address or username per window, and the dashboard
  marks them as *Locked* and counts how many are locked out now.

A lock for a known username can be triggered by anyone who types it wrongly often enough; that is the
price of per-account limits, and the reset exists for it. The limits are read from the sign-in history,
which keeps the latest 5000 attempts.

## IP rules

`/admin/ip-rules` controls which network addresses may sign in. Rules are single addresses or CIDR ranges (IPv4 and IPv6).

- A deny rule always wins.
- When any allow rule exists, only matching addresses can sign in. With no rules, everyone can.
- Rules apply to the browser pages (sign-in, `/authorize`, `/admin`, `/account`, logout) and to requests for tokens (`/token`, `/device_authorization`), global rules and those of the client making the request. A client can opt out of the general rules on its IP rules tab and is then judged by its own rules only. A refused token request gets `403 access_denied`. Userinfo, introspection, revocation and JWKS are not affected.
- A rule that would block your own address is refused, so you cannot lock yourself out.
- Refused sign-ins show as "Blocked" in the sign-in history.

## Theming and production

Login, account settings and error pages can be themed with Twig by mounting a folder at `/theme`:
see [docs/theming.md](docs/theming.md). A ready-to-use starter (compose file, example theme) lives in
the app-skeleton repository.

```bash
docker build -f Dockerfile.production -t php-sso:local .
```

The production image runs Apache as an unprivileged user on port 8080. Mount a volume at `/app/var`
(database and signing keys). Configuration through environment variables:

| Variable | Purpose |
| --- | --- |
| `SSO_PUBLIC_URL` | Public base URL; becomes the token issuer |
| `ADMIN_PASSWORD` | Password of the first administrator (required while the database is empty) |
| `ADMIN_EMAIL` | Email of that administrator (optional) |
| `SSO_APP_NAME` | Name shown on the pages |
| `SSO_LOGIN_IDENTIFIER` | What people type to sign in: `username` (default), `email` or `username_or_email`. The email modes need a different email address per user: an address shared by two people matches no one |
| `SSO_GOOGLE_CLIENT_ID`, `SSO_GOOGLE_CLIENT_SECRET` | Turn on "Continue with Google" |
| `SSO_GITHUB_CLIENT_ID`, `SSO_GITHUB_CLIENT_SECRET` | Turn on "Continue with GitHub" |
| `SSO_OIDC_ISSUER`, `SSO_OIDC_CLIENT_ID`, `SSO_OIDC_CLIENT_SECRET`, `SSO_OIDC_LABEL` | Turn on any other OpenID Connect provider (Microsoft, Okta, Keycloak...) under the button label you give |
| `SSO_MFA_POLICY` | Who must set up two-step verification: `optional` (default), `admins` or `everyone`. An administrator can change it at `/admin/security`, and what is saved there wins |
| `SSO_THEME_DIR` | Theme folder, default `/theme` |
| `SSO_DEBUG` | `1` shows theme errors instead of falling back |
| `SSO_TRUSTED_PROXIES` | Comma separated addresses or CIDR ranges of your reverse proxies. Only their `X-Forwarded-For` is believed; set it when behind a proxy, or IP rules see the proxy's address |
| `SSO_PKCE_OPTIONAL_FOR_CONFIDENTIAL_CLIENTS` | `1` stops requiring PKCE of confidential clients. Public clients always need it. It exists so the OpenID conformance suite can run (see `conformance/README.md`); leave it off in production |
| `SSO_DEMO_DATA` | `1` seeds a demo user, group and client with known passwords (development only) |

## Account page

Signed-in people manage their own profile at `/account`: name, email, password, and the browsers
they are signed in on.

## Layout

- `src/Sso.php`: HTTP endpoints (PSR-7 request in, response out)
- `src/Admin/AdminController.php` + `templates/admin/`: the admin panel (Twig)
- `src/Pages.php`, `src/Account/`, `templates/pages/`: themeable pages (login, account, errors)
- `src/Store/`: the storage interfaces (users, groups, clients, tokens and the rest) and `Storage`, the one door to them; `src/Store/Sqlite/` is the SQLite implementation, and the only place that knows about SQL
- `src/OAuth/`: what `php-sso/oauth-server` needs from us: clients from the database, the access policy, the sign-in, "no access" and logout screens, the device page, and what it asks of the token stores
- `src/Integration/Saml/`: what `php-sso/saml` needs from us: the company providers and the requests waiting for an answer, on top of the SQLite stores
- `src/Integration/Scim/`: what `php-sso/scim-server` needs from us: the SCIM clients, the people and groups they manage, accounts, our groups and the request log, each on top of the SQLite stores
- `src/Net/`: client address handling for the IP rules, and a small PSR-18 HTTP client for back-channel logout
- `config/config.php`: scopes, TTLs and the first-start seed data
- `client/public/index.php`: demo relying party; verifies the ID token signature, `iss`, `aud` and `nonce`

## Not production ready

- The seed credentials (`admin` / `admin123`, `alice`, `demo-secret`) are demo values.
- Back-channel logout is sent synchronously and once, with no retry queue.
- No audit log, no password reset, no pagination on the admin Users, Groups and Clients lists (group members are paged, and search is done on the server).
- No consent screen (first-party apps only; `prompt=consent` is refused), no front-channel logout.
- PHP's built-in web server; HTTP instead of HTTPS.
- Signing keys are generated into `var/keys` with no rotation.
