---
title: "Sign-in protection"
weight: 7
lead: "Limits on failed attempts."
---

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
