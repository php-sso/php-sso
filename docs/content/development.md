---
title: "Development"
weight: 15
lead: "Run the demo, and the tests, from a checkout."
---

This is how to work on php-sso, not how to run it: for that, see [Run with Docker]({{< relref "docker.md" >}}) and
[Install with Composer]({{< relref "composer.md" >}}). From a checkout of the repository:

```bash
docker compose up --build
```

- SSO server: http://localhost:9000
- Demo client: http://localhost:9001 (click "Sign in with php-sso", user `alice` / `password123`)
- Second demo client: http://localhost:9002. Sign in on one and the other signs you in without asking for a password; signing out of one ends both.
- Admin panel: http://localhost:9000/admin (user `admin` / `admin123`, or `ADMIN_PASSWORD` if set)

The first start runs `composer install` into `./vendor` (the libraries come from Packagist) and generates signing keys in
`./var/keys`. The production image is built with `docker build -f Dockerfile.production -t php-sso:local .`.
If your host user id is not 1000, set `UID` and `GID` in a `.env` file.

## Tests

```bash
docker compose exec sso vendor/bin/phpunit
```

The suite runs on SQLite. To run it against MySQL or PostgreSQL, start them and name the (empty) database it may use:

```bash
docker compose --profile databases up -d
docker compose exec -e SSO_TEST_DATABASE=mysql://sso:sso@mysql/sso_test sso vendor/bin/phpunit
docker compose exec -e SSO_TEST_DATABASE=pgsql://sso:sso@postgres/sso_test sso vendor/bin/phpunit
```

## Writing a migration

A change to the schema is a migration, never an edit of a statement in an earlier one (the schema classes `SqliteSchema`, `MysqlSchema` and
`PostgresSchema` are the frozen baseline, migration `0001`). Add a class in `src/Store/Sql/Migrations/`, extend `SqlMigration`, give it the next
id, and add it to the list in `Migrations::all()`:

```php
final class AddAuditLog extends SqlMigration
{
    public function id(): string { return '0002'; }

    public function description(): string { return 'Audit log'; }

    public function up(Connection $db): void
    {
        if (!$db->tableExists('audit_log')) {
            $this->exec($db, [
                'sqlite' => 'CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, at INTEGER NOT NULL)',
                'mysql' => 'CREATE TABLE audit_log (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, at BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin',
                'pgsql' => 'CREATE TABLE audit_log (id BIGSERIAL PRIMARY KEY, at BIGINT NOT NULL)',
            ]);
        }
    }
}
```

- Branch on `$db->dialect->name()` (`sqlite`, `mysql`, `pgsql`), or pass `exec()` an array keyed by it. A dialect with no SQL is an error.
- Do not open a transaction. SQLite and PostgreSQL run the migration and the record of it in one transaction (`Dialect::transactionalDdl()`),
  so a failure leaves nothing behind. SQLite also has foreign keys switched off meanwhile, so a table rebuild works, and they are checked before the commit.
- **MySQL cannot roll back DDL**, so write every step to be safe to run twice, with the guards `$db->tableExists()`, `columnExists()` and
  `indexExists()` (or `addColumn()` in `SqlMigration`). A migration that fails on MySQL is retried from its first step.
- Keep the schema parity test green: a table or column added by a migration must exist in all three databases (`SameTablesAsSqliteTest`).
- Test a migration with the real database through `TestConnection`; the `Migrator` takes a list of migrations, so a test can run fakes
  (see `tests/unit/PhpSso/Support/FakeMigration.php` and `UsesMigrationSandbox.php`) without touching the real list.

The OpenID Connect conformance suite is run as described in `conformance/README.md`.
