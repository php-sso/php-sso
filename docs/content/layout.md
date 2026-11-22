---
title: "Code layout"
weight: 12
lead: "Where things are in the source."
---

- `src/Sso.php`: HTTP endpoints (PSR-7 request in, response out)
- `src/Admin/AdminController.php` + `templates/admin/`: the admin panel (Twig)
- `src/Pages.php`, `src/Account/`, `templates/pages/`: themeable pages (login, account, errors)
- `src/Store/`: the storage interfaces (users, groups, clients, tokens and the rest) and `Storage`, the one door to them; `src/Store/Sql/` implements them for SQLite, MySQL and PostgreSQL, and is the only place that knows about SQL: the stores write the SQL the three share, and a `Dialect` (with one schema per database) supplies the rest
- `src/OAuth/`: what `php-sso/oauth-server` needs from us: clients from the database, the access policy, the sign-in, "no access" and logout screens, the device page, and what it asks of the token stores
- `src/Integration/Saml/`: what `php-sso/saml` needs from us: the company providers and the requests waiting for an answer, on top of the stores
- `src/Integration/Scim/`: what `php-sso/scim-server` needs from us: the SCIM clients, the people and groups they manage, accounts, our groups and the request log, each on top of the stores
- `src/Net/`: client address handling for the IP rules, and a small PSR-18 HTTP client for back-channel logout
- `config/config.php`: scopes, TTLs and the first-start seed data
- `client/public/index.php`: demo relying party; verifies the ID token signature, `iss`, `aud` and `nonce`
