---
title: "Admin panel"
weight: 4
lead: "Manage people, groups, clients and settings."
---

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
