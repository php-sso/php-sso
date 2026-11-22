---
title: "Install with Composer"
weight: 2
lead: "Run php-sso on your own PHP and web server."
group: "Get started"
---

```bash
composer create-project --no-dev php-sso/php-sso my-sso
```

That downloads the application into `my-sso/` and installs its libraries from Packagist. The files are yours. You need PHP 8.3 or
later with the `openssl` and `pdo_sqlite` extensions (or `pdo_mysql` or `pdo_pgsql` for [another database]({{< relref "production.md#databases" >}})),
and Composer 2.

## The web server

Point the document root at `my-sso/public` and send every request that is not a file to `index.php`. The server must pass the
`Authorization` header to PHP, because clients authenticate to `/token` with it.

Apache:

```apache
<VirtualHost *:443>
    DocumentRoot /var/www/my-sso/public
    FallbackResource /index.php
    SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
    <Directory /var/www/my-sso/public>
        Options -Indexes
        AllowOverride None
        Require all granted
    </Directory>
</VirtualHost>
```

nginx with PHP-FPM:

```nginx
server {
    listen 443 ssl;
    root /var/www/my-sso/public;
    location / { try_files $uri /index.php$is_args$args; }
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }
    location ~ \.php$ { return 404; }
}
```

## Settings

The settings are environment variables, the same as for the [Docker image]({{< relref "docker.md" >}}) (see
[Production and configuration]({{< relref "production.md" >}})). Give them to the web server or to PHP-FPM; PHP-FPM clears the environment
unless the pool has `clear_env = no` (or lists each variable with `env[NAME] = ...`).

`my-sso/var/` must be writable by the web server user. It holds the SQLite database, the keys and a cache. Keep it out of backups
you do not trust, and in the ones you do.

The first time it runs with an empty database, it creates the administrator (`admin`, or the name in `ADMIN_USERNAME`) with the password in `ADMIN_PASSWORD`.

Run `bin/maintenance` from cron, every minute or every few minutes, to remove expired sessions and tokens and to send again the logout notifications that apps did not accept:

```
* * * * * cd /var/www/my-sso && php bin/maintenance
```

## Your own look

Set `SSO_THEME_DIR` to a folder of yours, outside the project, so that an upgrade does not touch it: see [Theming]({{< relref "theming.md" >}}).

## Upgrading

The project is an application you own, so `composer update` only updates the libraries it uses, not the application. To move to a new
version, create a new project of that version next to the old one, move `var/` across (it holds your data and keys) and use the same
environment variables and theme folder. Keep your own changes out of the application's files and this stays a copy and a restart. The new version brings the database up to date
when it starts; `bin/migrate status` and `bin/migrate up` do it as a separate step (see [Migrations]({{< relref "production.md#migrations" >}})).
For a setup you would rather not maintain this way, use the [Docker image]({{< relref "docker.md" >}}).
