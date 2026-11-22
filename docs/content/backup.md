---
title: "Backup and restore"
weight: 20
lead: "Everything the server needs, in one file."
group: "Operate"
---

```bash
bin/backup                         # php-sso-backup-<date>.tar.gz in the current folder
bin/backup /backups/sso.tar.gz     # or a name you choose
```

The server keeps running while it is made: the data is read in one consistent snapshot, so the file is a picture of one moment, not a mix of several. A backup
never overwrites an existing file.

## What is in it

- **The data**: every table (people, groups, apps, roles, settings, sessions, tokens, sign-in history, the [audit log]({{< relref "security/audit-log.md" >}}) and the rest), in a form that does not depend on the database.
- **The keys**: the files in `var/keys`: the signing keys (with the ones from [rotations]({{< relref "security/signing-keys.md" >}})) and `mfa.key`, which decrypts the saved
  authenticator secrets.

What is not in it, because it is yours or can be made again: your settings (the environment variables), your [theme]({{< relref "theming.md" >}}), the cache, and PHP's session
files. After a restore, people who were signed in are asked to sign in again.

**The file holds password hashes and the signing keys.** It is readable by you only, and it should stay that way: keep it where you keep the server's own secrets, and encrypt it
when it leaves the machine (for example with `age` or `gpg`).

## Restore

```bash
bin/restore sso.tar.gz              # into an empty database, on an installation with no keys yet
bin/restore sso.tar.gz --replace    # empty the database first, and move the existing var/keys aside
bin/restore sso.tar.gz --without-keys   # the data only
```

Stop the server first. Without `--replace`, a restore refuses a database that has people in it and a `var/keys` that has keys in it, so it cannot overwrite a working server by mistake.
With `--replace` the existing keys are not deleted but moved to `var/keys.before-restore-<time>`. A restore is all or nothing: if the file is damaged or does not fit, the database is left as it was.

If someone opens a newly started server before you restore, the first administrator is created from `ADMIN_PASSWORD` and the database is no longer empty: use `--replace`.

A backup from an older version restores into a newer one. One from a newer version is refused with the name of the table or column this version does not know.

## Moving to another database

The file does not care what kind of database it came from, so a restore is also how to move between them: take the backup on SQLite, point the settings at an empty MySQL or PostgreSQL database
(see [Databases]({{< relref "production.md#databases" >}})), and restore. People keep their passwords and the same signing keys, so apps notice nothing.

## With the Docker image

The file has to be made inside the container and copied out:

```bash
docker exec sso php bin/backup /tmp/sso-backup.tar.gz
docker cp sso:/tmp/sso-backup.tar.gz .
docker exec sso rm /tmp/sso-backup.tar.gz
```

To restore into a new container (with its own empty volume), copy the file in and run `bin/restore`:

```bash
docker cp sso-backup.tar.gz sso:/tmp/
docker exec sso php bin/restore /tmp/sso-backup.tar.gz
docker exec sso rm /tmp/sso-backup.tar.gz
```

## On a schedule

From cron, once a night, keeping the last two weeks:

```
0 3 * * * cd /var/www/my-sso && php bin/backup /backups/sso-$(date +\%F).tar.gz && find /backups -name 'sso-*.tar.gz' -mtime +14 -delete
```

A backup you have never restored is a hope, not a backup: restore one into a scratch database now and then and sign in.

For a very large installation, the database server's own tools (`mysqldump`, `pg_dump`) are the better way to copy the data; then copy `var/keys` as well. `bin/backup` reads
every row through PHP, which is simple and works everywhere, but it is not made for tens of millions of rows.
