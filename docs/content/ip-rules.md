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
