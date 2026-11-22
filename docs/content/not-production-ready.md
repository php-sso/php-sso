---
title: "Not production ready"
weight: 11
lead: "What the demo leaves out."
---

- The seed credentials (`admin` / `admin123`, `alice`, `demo-secret`) are demo values.
- Back-channel logout is sent synchronously and once, with no retry queue.
- No audit log, no password reset, no pagination on the admin Users, Groups and Clients lists (group members are paged, and search is done on the server).
- No consent screen (first-party apps only; `prompt=consent` is refused), no front-channel logout.
- PHP's built-in web server; HTTP instead of HTTPS.
- Signing keys are generated into `var/keys` with no rotation.
