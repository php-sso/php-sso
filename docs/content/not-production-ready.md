---
title: "Not production ready"
weight: 14
lead: "What is still missing."
---

- No password reset by email: an administrator sets a new password.
- No consent screen (first-party apps only; `prompt=consent` is refused), no front-channel logout.
- php-sso has not had an independent security review (see [Security]({{< relref "security/_index.md" >}})).
- The development setup (`docker compose`, see [Development]({{< relref "development.md" >}})) has demo passwords, PHP's built-in web server and HTTP. It is for
  working on php-sso. The [Docker image]({{< relref "docker.md" >}}) is what to run: it refuses weak first passwords and public HTTP addresses.
