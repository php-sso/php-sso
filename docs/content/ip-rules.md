---
title: "IP rules"
weight: 34
lead: "Allow or deny network addresses."
group: "Users and access"
---

`/admin/ip-rules` controls which network addresses may sign in. Rules are single addresses or CIDR ranges (IPv4 and IPv6).

- A deny rule always wins.
- When any allow rule exists, only matching addresses can sign in. With no rules, everyone can.
- Rules apply to the browser pages (sign-in, `/authorize`, `/admin`, `/account`, logout) and to requests for tokens (`/token`, `/device_authorization`), global rules and those of the client making the request. A client can opt out of the general rules on its IP rules tab and is then judged by its own rules only. A refused token request gets `403 access_denied`. Userinfo, introspection, revocation and JWKS are not affected.
- A rule that would block your own address is refused, so you cannot lock yourself out.
- Refused sign-ins show as "Blocked" in the sign-in history.

## Rules for one person

A user has an **IP rules** tab with the same allow and deny rules, for example to let one person sign in only from the office. They come on top of the general rules and those of each app: an address has to pass all of them, and a client that ignores the general rules does not ignore a person's.

- They are checked after the right password (or a social or SAML sign-in), and on every browser page of a person who is already signed in, so a session that moves to another address is refused too. Logout stays reachable.
- Tokens that an app already holds keep working until they expire, and an app's server refreshing them is not judged by the person's rules: the address that calls `/token` is the app's, not the person's.
- A rule on your own account that would block your address is refused. On other people's accounts you can add any rule and remove it again from the same tab.
- The rules go with the person when they are deleted. The access test (`/admin/access-test`) shows them as a step when you choose a person.
