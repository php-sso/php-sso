---
title: "Signing in"
weight: 30
lead: "Passwords, outside accounts, companies and a second step."
group: "Users and access"
---

Signing in takes two steps: the person says who they are (`/login`), then proves it with a password
(`/login/password`). The first step answers the same whether or not the account exists, so it does not
reveal who has one. What people type is set with `SSO_LOGIN_IDENTIFIER` (`username`, `email` or
`username_or_email`). The email modes need a different email address for every user, so the server
refuses to start while two users share one, and the admin forms reject duplicates. With
`username_or_email`, new usernames cannot contain `@`.

Someone who forgot their password can choose a new one themselves when email is set up: see [Email and password reset]({{< relref "sign-in/password-reset.md" >}}).

A user can exist without a local password (for example when provisioned from elsewhere); such a person
cannot sign in with a password.
