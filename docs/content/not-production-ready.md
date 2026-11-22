---
title: "Not production ready"
weight: 13
lead: "What the demo leaves out."
---

- Back-channel logout is sent synchronously and once, with no retry queue.
- No audit log, no password reset, no pagination on the admin Users, Groups and Clients lists (group members are paged, and search is done on the server).
- No consent screen (first-party apps only; `prompt=consent` is refused), no front-channel logout.
- Signing keys are generated into `var/keys` with no rotation.
- The development setup (`docker compose`, see [Development]({{< relref "development.md" >}})) has demo passwords, PHP's built-in web server and HTTP. It is for
  working on php-sso. The [Docker image]({{< relref "docker.md" >}}) is what to run: it refuses weak first passwords and public HTTP addresses.
