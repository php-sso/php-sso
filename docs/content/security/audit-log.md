---
title: "Audit log"
weight: 5
lead: "Who changed what, and when."
---

Every change an administrator makes, the changes people make to their own account, and the commands run on the server are written to the audit log. Read it in the admin panel at
**Audit log** (`/admin/audit`): newest first, 50 to a page, with filters for the action (`user.`, or `user.lock`), who did it, what it was about, and a range of dates. Times are UTC.

An entry has: when, who (the name and id of the person, and the address they came from), the action, what it was about (its id, and its name as it was at that moment) and, for some
actions, a few details. The names are copied into the entry, so it still says `client.delete  Billing app` after that app is gone.

## What is recorded

| Group | Actions |
| --- | --- |
| `user` | `create`, `update` (says whether administrator rights are on, and whether they were), `lock`, `unlock`, `delete`, `reset_failures`, `reset_mfa`, `unlink_connection`, `consent_ask_again`, `impersonate`, `send_reset` (an administrator sent a password reset link) |
| `impersonation` | `stop` (starting is `user.impersonate`) |
| `client` | `create`, `update`, `access`, `registrar`, `rotate_secret`, `delete`, `ip_rule_create`, `ip_rule_scope`, `ip_rule_delete` |
| `group`, `role` | `create`, `update`, `delete` |
| `relation` | `add`, `remove`, `remove_all`: people put in or taken out of a group, roles given or taken away (the entry names the group or role, and keeps the id of the person) |
| `scope`, `ip_rule`, `session` | `create`, `update` and `delete` for scopes; `create` and `delete` for IP rules; `end` for a browser session an administrator ended |
| `scim_client`, `saml_connection`, `consent` | `create`, `update`, `delete`; `rotate_token` for SCIM; `ask_everyone_again` for a notice |
| `security` | `accounts`, `settings`, `reset_failures`, `mfa_policy`, `social`: the sign-in protection pages (an entry names the fields that were saved, not their values; `mfa_policy` also says which policy) |
| `account` | What people do to their own account: `profile_update`, `password_change`, `mfa_enable`, `mfa_disable`, `mfa_recovery_codes`, `social_unlink`, `password_reset` (a new password chosen with an emailed link), `session_end`, `sessions_end_others` |
| `logout` | `undelivered`: an app did not accept a [logout notification](../../signing-out/) after every attempt. The person is `system` |
| `keys`, `backup` | From the command line: `keys.rotate`, `keys.prune`, `keys.saml_stage`, `keys.saml_activate`, `keys.saml_discard`, `backup.create`, `backup.restore`. The person is `cli:` and the name of the user who ran the command |

An entry is written only when the change was made. A form that was refused, a page that was not found and a code that was wrong leave nothing, so the log is not full of mistakes.

**What is never recorded:** a password, a secret, a token, or anything else that was typed into a form, except the few values named above (the administrator flag, the type and range of an IP rule,
the MFA policy). Looking at pages is not recorded either, and neither are sign-ins: those are in the sign-in history on the dashboard, with the failures and the blocks.
Linking an outside account (Google, GitHub) from the account page is not recorded yet; unlinking is.

## How long it is kept

Entries older than **365 days** are removed, by the [housekeeping]({{< relref "production.md#monitoring" >}}) that also clears expired tokens. Change that with `SSO_AUDIT_RETENTION_DAYS`; `0` keeps
them for ever. The log is part of a [backup]({{< relref "backup.md" >}}), so a restore brings it back with the rest.

## Sending it somewhere else

The log is in the same database as everything else, so whoever can change the database can change the log. If you need a record that the administrators of the server cannot alter, send it away as it is written:
with `SSO_AUDIT_STREAM=1` every entry is also written to the error log as one line, `SSO_AUDIT` and a JSON object:

```
SSO_AUDIT {"at":1795337223,"actor_sub":"2439cd34-...","actor":"admin","ip":"203.0.113.7","action":"user.lock","target_type":"user","target":"2439cd34-...","target_label":"alice","detail":[]}
```

With the Docker image that is the container's log (`docker logs`), which a log collector can ship to a system of your choice.

## If the log cannot be written

Recording is best effort: if the database refuses the entry, the change it describes still goes ahead, because a broken log should not stop an administrator from locking a stolen account. The failure
and the whole entry are written to the error log instead, so they are not lost.

## Adding an action

An action is a line in `src/Audit/AdminActions.php` (or `AccountActions.php`). A test fails if a route of the admin panel has no line there, so a new change cannot go unrecorded by accident.
