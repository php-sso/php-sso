---
title: "Signing out"
weight: 3
lead: "Logout, and how it reaches the apps."
---

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
