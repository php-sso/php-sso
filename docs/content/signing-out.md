---
title: "Signing out"
weight: 5
lead: "Logout, and how it reaches the apps."
---

- **Ending a session** (by the person, by an administrator, on password change, or when the user is
  deleted) revokes the auth codes, access tokens and refresh tokens issued during it. Refreshing and
  `/userinfo` stop working at once. Idle expiry alone does not revoke anything.
- **Back-channel logout:** give a client a *Back-channel logout URL* and it gets a signed logout token
  (`sid` and `sub` claims) by POST whenever a session it took part in ends. ID tokens carry the matching
  `sid`. The first attempt has a 3 second timeout. If the app cannot be reached or does not answer 2xx, the notification is kept and sent again after
  1 minute, 5 minutes, 30 minutes, 2 hours and 12 hours (six attempts in all), each time with a fresh token, because a logout token is valid for two minutes only.
  The retries are made by [`bin/maintenance`]({{< relref "production.md#monitoring" >}}) and by the server itself on some requests, so they happen even if nobody runs the
  command, only later and less exactly. An app that has not answered after the last attempt is given up on: that is written to the error log and to the
  [audit log]({{< relref "security/audit-log.md" >}}) as `logout.undelivered`. An app may receive the same notification twice (it answered with an error after it had acted on it),
  so it should treat a repeated `jti` or `sid` as already handled.
- **Single logout from an app:** the app sends the browser to `/end-session` with `id_token_hint`. Register
  its *Post-logout redirect URIs* in the client to let it come back. Without a hint, the person is asked
  to confirm first.
- A refresh also checks that the person still exists and is still allowed to use the client (group access).
- What cannot be undone: an access token or ID token that is already out is a signed JWT and stays valid
  until it expires (1 hour by default), and apps that do not support back-channel logout keep their own session.
