<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql;

use PDO;

/** The SQLite schema, and the steps that bring an older SQLite database up to date. */
final class SqliteSchema
{
    /**
     * Columns of the users table. `password_hash` is NULL for a person who has no local password (they sign in
     * through an identity provider, or were provisioned and have not set one yet).
     */
    private const USERS_COLUMNS = 'sub TEXT PRIMARY KEY,
            username TEXT NOT NULL UNIQUE COLLATE NOCASE,
            password_hash TEXT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            email_verified INTEGER NOT NULL DEFAULT 0,
            is_admin INTEGER NOT NULL DEFAULT 0,
            must_change_password INTEGER NOT NULL DEFAULT 0';

    public function __construct(private PDO $pdo)
    {
    }

    /** Creates the tables, and brings an older database up to date. */
    public function install(): void
    {

        // Authorization codes and refresh tokens, stored as hashes. Access tokens are signed JWTs and are not stored:
        // they carry a family id, and a revoked family (below) makes them fail.
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS oauth_codes (
            code_hash TEXT PRIMARY KEY,
            client_id TEXT NOT NULL,
            user_id TEXT NOT NULL,
            redirect_uri TEXT NOT NULL,
            scopes TEXT NOT NULL,
            code_challenge TEXT NOT NULL,
            nonce TEXT,
            session_id TEXT,
            family_id TEXT NOT NULL,
            expires_at INTEGER NOT NULL,
            used_at INTEGER)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS oauth_refresh_tokens (
            token_hash TEXT PRIMARY KEY,
            family_id TEXT NOT NULL,
            client_id TEXT NOT NULL,
            user_id TEXT NOT NULL,
            scopes TEXT NOT NULL,
            session_id TEXT,
            expires_at INTEGER NOT NULL,
            used_at INTEGER)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS oauth_revoked_families (
            family_id TEXT PRIMARY KEY, revoked_at INTEGER NOT NULL)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS oauth_codes_session ON oauth_codes (session_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS oauth_refresh_tokens_session ON oauth_refresh_tokens (session_id)');
        // When the person last typed their password: carried into every ID token of a sign-in (`auth_time`, `max_age`).
        $this->addColumn('oauth_codes', 'auth_time', 'INTEGER');
        $this->addColumn('oauth_refresh_tokens', 'auth_time', 'INTEGER');
        // How the person signed in (`amr`, a JSON list such as ["pwd","otp","mfa"]): kept with the code and every refresh token of its chain.
        $this->addColumn('oauth_codes', 'amr', 'TEXT');
        $this->addColumn('oauth_refresh_tokens', 'amr', 'TEXT');
        // Access tokens that were revoked one by one (RFC 7009), remembered until they would have expired anyway.
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS oauth_revoked_tokens (
            token_id TEXT PRIMARY KEY, expires_at INTEGER NOT NULL)');
        // Device authorization requests (RFC 8628): hashes of the two codes, and where the request stands.
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS oauth_device_requests (
            device_code_hash TEXT PRIMARY KEY,
            user_code_hash TEXT NOT NULL UNIQUE,
            client_id TEXT NOT NULL,
            scopes TEXT NOT NULL,
            family_id TEXT NOT NULL,
            expires_at INTEGER NOT NULL,
            interval_seconds INTEGER NOT NULL,
            status TEXT NOT NULL,
            user_id TEXT,
            session_id TEXT,
            last_polled_at INTEGER,
            consumed_at INTEGER)');
        // Wrong user codes typed on the device page, so one person cannot guess them at will.
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS device_failures (
            user_sub TEXT NOT NULL, created_at INTEGER NOT NULL)');
        // Tokens of the previous OAuth library were only ids and cannot be used any more: people sign in again.
        foreach (['auth_codes', 'access_tokens', 'refresh_tokens'] as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS {$table}");
        }

        // Managed through the admin panel.
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS users (' . self::USERS_COLUMNS . ')');
        $this->allowUsersWithoutPassword();
        // An administrator can require a person to choose their own password at the next sign-in.
        $this->addColumn('users', 'must_change_password', 'INTEGER NOT NULL DEFAULT 0');
        // When an administrator locked the account (NULL: not locked). A locked account cannot sign in and has no live sessions.
        $this->addColumn('users', 'locked_at', 'INTEGER');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS user_group (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE COLLATE NOCASE,
            description TEXT NOT NULL DEFAULT \'\')');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS user_group_member (
            user_sub TEXT NOT NULL REFERENCES users(sub) ON DELETE CASCADE,
            group_id INTEGER NOT NULL REFERENCES user_group(id) ON DELETE CASCADE,
            PRIMARY KEY (user_sub, group_id))');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS clients (
            client_id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            secret_hash TEXT,
            confidential INTEGER NOT NULL,
            redirect_uris TEXT NOT NULL,
            grants TEXT NOT NULL)');

        // Which groups may sign in to a client. A client with no rows here is open to every user.
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS client_group (
            client_id TEXT NOT NULL REFERENCES clients(client_id) ON DELETE CASCADE,
            group_id INTEGER NOT NULL REFERENCES user_group(id) ON DELETE CASCADE,
            PRIMARY KEY (client_id, group_id))');

        // Sign-in history and SSO browser sessions, shown on the admin dashboard.
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS login_sessions (
            id TEXT PRIMARY KEY,
            user_sub TEXT NOT NULL REFERENCES users(sub) ON DELETE CASCADE,
            created_at INTEGER NOT NULL,
            last_seen INTEGER NOT NULL,
            ended_at INTEGER,
            ip TEXT NOT NULL DEFAULT \'\',
            user_agent TEXT NOT NULL DEFAULT \'\')');
        // The administrator who started this session by browsing as the person (see Auth\Impersonation); NULL for a real sign-in.
        $this->addColumn('login_sessions', 'impersonated_by', 'TEXT');
        $this->addColumn('login_sessions', 'amr', 'TEXT'); // how this session's person signed in, as a JSON list (see Sessions::start)
        // Failed client authentications at the token, introspection, revocation and device endpoints, to slow down guessing.
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS client_auth_failures (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id TEXT NOT NULL,
            ip TEXT NOT NULL,
            created_at INTEGER NOT NULL)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS client_auth_failures_ip ON client_auth_failures (ip, created_at)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            user_sub TEXT REFERENCES users(sub) ON DELETE SET NULL,
            success INTEGER NOT NULL,
            ip TEXT NOT NULL DEFAULT \'\',
            user_agent TEXT NOT NULL DEFAULT \'\',
            created_at INTEGER NOT NULL)');

        // Added after the first release: older databases get the columns on first start.
        $this->addColumn('clients', 'scopes', 'TEXT'); // JSON list of the scopes the client may ask for; NULL means every scope the server offers
        $this->addColumn('clients', 'backchannel_logout_uri', 'TEXT');
        $this->addColumn('clients', 'post_logout_redirect_uris', "TEXT NOT NULL DEFAULT '[]'");
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS client_user (
            client_id TEXT NOT NULL REFERENCES clients(client_id) ON DELETE CASCADE,
            user_sub TEXT NOT NULL REFERENCES users(sub) ON DELETE CASCADE,
            PRIMARY KEY (client_id, user_sub))');
        // Access is either open to every user, or limited to the groups and people linked to the client.
        // Clients that already had groups were limited to them, so they stay limited.
        if ($this->addColumn('clients', 'restricted', 'INTEGER NOT NULL DEFAULT 0')) {
            $this->pdo->exec('UPDATE clients SET restricted = 1 WHERE client_id IN (SELECT client_id FROM client_group)');
        }
        // Which IP addresses may sign in (see IpPolicy). `blocked` marks attempts that were refused because of them.
        // A rule with no client applies to everyone; with a client, only to signing in to that client.
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ip_rules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id TEXT REFERENCES clients(client_id) ON DELETE CASCADE,
            type TEXT NOT NULL CHECK (type IN (\'allow\', \'deny\')),
            cidr TEXT NOT NULL,
            note TEXT NOT NULL DEFAULT \'\',
            created_at INTEGER NOT NULL)');
        $ipRuleColumns = array_column($this->pdo->query('PRAGMA table_info(ip_rules)')->fetchAll(), 'name');
        if (!in_array('client_id', $ipRuleColumns, true)) { // first version: global rules only
            $this->pdo->exec('ALTER TABLE ip_rules RENAME TO ip_rules_old');
            $this->pdo->exec('CREATE TABLE ip_rules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id TEXT REFERENCES clients(client_id) ON DELETE CASCADE,
                type TEXT NOT NULL CHECK (type IN (\'allow\', \'deny\')),
                cidr TEXT NOT NULL,
                note TEXT NOT NULL DEFAULT \'\',
                created_at INTEGER NOT NULL)');
            $this->pdo->exec('INSERT INTO ip_rules (id, type, cidr, note, created_at) SELECT id, type, cidr, note, created_at FROM ip_rules_old');
            $this->pdo->exec('DROP TABLE ip_rules_old');
        }
        // NULL client ids are distinct in a UNIQUE constraint, so global duplicates are prevented with an index on the coalesced value.
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS ip_rules_unique ON ip_rules (COALESCE(client_id, \'\'), type, cidr)');
        // A client that ignores the general IP rules is judged by its own rules only.
        $this->addColumn('clients', 'ignore_general_ip_rules', 'INTEGER NOT NULL DEFAULT 0');
        $this->addColumn('login_attempts', 'blocked', 'INTEGER NOT NULL DEFAULT 0');
        // `throttled`: refused because of too many recent failures. `cleared`: an administrator reset the failure count,
        // so the attempt stays in the history but no longer counts towards a limit.
        $this->addColumn('login_attempts', 'throttled', 'INTEGER NOT NULL DEFAULT 0');
        $this->addColumn('login_attempts', 'cleared', 'INTEGER NOT NULL DEFAULT 0');
        // What was checked: the password (the default), or the code of the second step.
        $this->addColumn('login_attempts', 'factor', "TEXT NOT NULL DEFAULT 'password'");
        // Settings an administrator changes in the admin panel (values are JSON or plain text).
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        // Scopes an administrator added (names that go into access tokens, with no claims). The built-in ones (openid, profile...) live in the config: the code behind
        // them is fixed.
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS scopes (
            name TEXT PRIMARY KEY,
            description TEXT NOT NULL DEFAULT \'\',
            created_at INTEGER NOT NULL)');
        // Accounts at outside providers (Google, GitHub...) that people sign in with. `subject` is the provider's own
        // permanent id for the person; the email is only a note of what the provider said last.
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS user_identities (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_sub TEXT NOT NULL REFERENCES users(sub) ON DELETE CASCADE,
            provider TEXT NOT NULL,
            subject TEXT NOT NULL,
            email TEXT,
            created_at INTEGER NOT NULL,
            last_login_at INTEGER,
            UNIQUE (provider, subject))');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS user_identities_user ON user_identities (user_sub)');
        // Company identity providers (SAML). A connection claims email domains; a person at one of them signs in through it.
        // People who signed in that way are rows of user_identities with the provider `saml-<connection id>`.
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS saml_connections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            idp_entity_id TEXT NOT NULL,
            idp_sso_url TEXT NOT NULL,
            idp_certificate TEXT NOT NULL,
            enabled INTEGER NOT NULL DEFAULT 1,
            create_users INTEGER NOT NULL DEFAULT 0,
            link_existing INTEGER NOT NULL DEFAULT 0)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS saml_domains (
            domain TEXT PRIMARY KEY COLLATE NOCASE,
            connection_id INTEGER NOT NULL REFERENCES saml_connections(id) ON DELETE CASCADE)');
        // Sign-in requests sent to a company provider that have not been answered yet (see SamlRequests).
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS saml_requests (
            request_id TEXT PRIMARY KEY,
            connection_id INTEGER NOT NULL REFERENCES saml_connections(id) ON DELETE CASCADE,
            binding_hash TEXT NOT NULL,
            return_to TEXT,
            created_at INTEGER NOT NULL)');
        // Two-step verification: the authenticator app of a person (secret sealed with var/keys/mfa.key) and their
        // single-use recovery codes (SHA-256 of the code).
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS user_mfa (
            user_sub TEXT PRIMARY KEY REFERENCES users(sub) ON DELETE CASCADE,
            secret TEXT NOT NULL,
            last_counter INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS mfa_recovery_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_sub TEXT NOT NULL REFERENCES users(sub) ON DELETE CASCADE,
            code_hash TEXT NOT NULL,
            used_at INTEGER)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS mfa_recovery_codes_user ON mfa_recovery_codes (user_sub)');
        // Answers from other servers kept for a while (an OpenID provider's discovery document and keys).
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS http_cache (
            cache_key TEXT PRIMARY KEY,
            body TEXT NOT NULL,
            fetched_at INTEGER NOT NULL)');
        // Notices people must accept before they are signed in. The content lives elsewhere (a link); `version` only counts
        // how often an administrator asked everyone to accept again. An acceptance keeps a copy of what was shown.
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS consents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            message TEXT NOT NULL DEFAULT \'\',
            url TEXT,
            version INTEGER NOT NULL DEFAULT 1,
            enabled INTEGER NOT NULL DEFAULT 1,
            position INTEGER NOT NULL DEFAULT 0)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS user_consents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_sub TEXT NOT NULL REFERENCES users(sub) ON DELETE CASCADE,
            consent_id INTEGER NOT NULL REFERENCES consents(id) ON DELETE RESTRICT,
            version INTEGER NOT NULL,
            accepted_at INTEGER NOT NULL,
            ip TEXT,
            name TEXT NOT NULL,
            message TEXT NOT NULL,
            url TEXT,
            withdrawn_at INTEGER)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS user_consents_lookup ON user_consents (user_sub, consent_id)');
        // SCIM: companies' systems that create, update and deactivate people here (see src/Scim). A client holds a token
        // (only its hash is kept) and the email domains it may manage. `scim_users` ties a person to the client that
        // provisioned them, with the client's own identifiers for them.
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS scim_clients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            token_hash TEXT NOT NULL UNIQUE,
            domains TEXT NOT NULL DEFAULT \'[]\',
            enabled INTEGER NOT NULL DEFAULT 1,
            on_delete TEXT NOT NULL DEFAULT \'deactivate\',
            adopt_existing INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL,
            last_used_at INTEGER)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS scim_users (
            user_sub TEXT PRIMARY KEY REFERENCES users(sub) ON DELETE CASCADE,
            client_id INTEGER NOT NULL REFERENCES scim_clients(id) ON DELETE CASCADE,
            user_name TEXT NOT NULL COLLATE NOCASE,
            external_id TEXT,
            given_name TEXT,
            family_name TEXT)');
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS scim_users_user_name ON scim_users (client_id, user_name)');
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS scim_users_external_id ON scim_users (client_id, external_id) WHERE external_id IS NOT NULL');
        // SCIM groups: a group a client provisioned, with the client's own name and id for it. A client may add a prefix
        // to the names it creates, and may be allowed to take over a group that already exists.
        $this->addColumn('scim_clients', 'group_prefix', "TEXT NOT NULL DEFAULT ''");
        $this->addColumn('scim_clients', 'adopt_groups', 'INTEGER NOT NULL DEFAULT 0');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS scim_groups (
            group_id INTEGER PRIMARY KEY REFERENCES user_group(id) ON DELETE CASCADE,
            client_id INTEGER NOT NULL REFERENCES scim_clients(id) ON DELETE CASCADE,
            display_name TEXT NOT NULL COLLATE NOCASE,
            external_id TEXT)');
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS scim_groups_display_name ON scim_groups (client_id, display_name)');
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS scim_groups_external_id ON scim_groups (client_id, external_id) WHERE external_id IS NOT NULL');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS scim_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL REFERENCES scim_clients(id) ON DELETE CASCADE,
            at INTEGER NOT NULL,
            method TEXT NOT NULL,
            path TEXT NOT NULL,
            status INTEGER NOT NULL,
            detail TEXT NOT NULL DEFAULT \'\')');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS session_clients (
            session_id TEXT NOT NULL REFERENCES login_sessions(id) ON DELETE CASCADE,
            client_id TEXT NOT NULL REFERENCES clients(client_id) ON DELETE CASCADE,
            PRIMARY KEY (session_id, client_id))');
        // Roles are defined once and given to groups and people once. `all_clients` makes a role part of every app's
        // `roles` claim; otherwise only the apps listed in role_client receive it. A person has a role when it is given
        // to them directly or to a group they are in.
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS role (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE COLLATE NOCASE,
            description TEXT NOT NULL DEFAULT \'\',
            all_clients INTEGER NOT NULL DEFAULT 1)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS role_client (
            role_id INTEGER NOT NULL REFERENCES role(id) ON DELETE CASCADE,
            client_id TEXT NOT NULL REFERENCES clients(client_id) ON DELETE CASCADE,
            PRIMARY KEY (role_id, client_id))');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS role_group (
            role_id INTEGER NOT NULL REFERENCES role(id) ON DELETE CASCADE,
            group_id INTEGER NOT NULL REFERENCES user_group(id) ON DELETE CASCADE,
            PRIMARY KEY (role_id, group_id))');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS role_user (
            role_id INTEGER NOT NULL REFERENCES role(id) ON DELETE CASCADE,
            user_sub TEXT NOT NULL REFERENCES users(sub) ON DELETE CASCADE,
            PRIMARY KEY (role_id, user_sub))');
        $this->migrateClientRoles();
    }

    /**
     * Roles used to belong to one client each (tables client_role*). They become global roles available in that client.
     * Two clients with a role of the same name and the same holders share one role; with different holders the second
     * is named `<name>-<client id>`, so nobody receives a role they did not have (the app notices the new name).
     */
    private function migrateClientRoles(): void
    {
        $exists = $this->pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'client_role'")->fetchColumn();
        if ($exists === false) {
            return;
        }

        $holders = function (string $table, string $column, int $roleId): array {
            $stmt = $this->pdo->prepare("SELECT {$column} FROM {$table} WHERE role_id = ? ORDER BY 1");
            $stmt->execute([$roleId]);

            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        };

        $this->pdo->beginTransaction();
        foreach ($this->pdo->query('SELECT id, client_id, name, description FROM client_role ORDER BY id')->fetchAll() as $old) {
            $groups = $holders('client_role_group', 'group_id', (int) $old['id']);
            $users = $holders('client_role_user', 'user_sub', (int) $old['id']);

            $name = $old['name'];
            $find = $this->pdo->prepare('SELECT id FROM role WHERE name = ?');
            $find->execute([$name]);
            $roleId = $find->fetchColumn();
            $find->closeCursor(); // an open statement would keep the old tables locked
            if ($roleId !== false && ($holders('role_group', 'group_id', (int) $roleId) !== $groups || $holders('role_user', 'user_sub', (int) $roleId) !== $users)) {
                $name = $old['name'] . '-' . $old['client_id'];
                error_log("SSO: the role {$old['name']} of {$old['client_id']} has other holders than a role of the same name in another client, so it is now called {$name}.");
                $roleId = false;
            }
            if ($roleId === false) {
                $this->pdo->prepare('INSERT INTO role (name, description, all_clients) VALUES (?, ?, 0)')->execute([$name, $old['description']]);
                $roleId = (int) $this->pdo->lastInsertId();
                foreach ($groups as $g) {
                    $this->pdo->prepare('INSERT OR IGNORE INTO role_group (role_id, group_id) VALUES (?, ?)')->execute([$roleId, $g]);
                }
                foreach ($users as $u) {
                    $this->pdo->prepare('INSERT OR IGNORE INTO role_user (role_id, user_sub) VALUES (?, ?)')->execute([$roleId, $u]);
                }
            }
            $this->pdo->prepare('INSERT OR IGNORE INTO role_client (role_id, client_id) VALUES (?, ?)')->execute([$roleId, $old['client_id']]);
        }
        $this->pdo->exec('DROP TABLE client_role_group');
        $this->pdo->exec('DROP TABLE client_role_user');
        $this->pdo->exec('DROP TABLE client_role');
        $this->pdo->commit();
    }

    /** @return bool whether the column was added just now */
    private function addColumn(string $table, string $column, string $definition): bool
    {
        $columns = array_column($this->pdo->query("PRAGMA table_info({$table})")->fetchAll(), 'name');
        if (!in_array($column, $columns, true)) {
            $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");

            return true;
        }

        return false;
    }

    /**
     * Older databases require a password for every user. SQLite cannot drop that constraint in place, so the table is
     * rebuilt (the procedure from the SQLite documentation, with foreign keys off while it runs).
     */
    private function allowUsersWithoutPassword(): void
    {
        $columns = $this->pdo->query('PRAGMA table_info(users)')->fetchAll();
        $password = array_values(array_filter($columns, static fn (array $c): bool => $c['name'] === 'password_hash'))[0] ?? null;
        if ($password === null || (int) $password['notnull'] === 0) {
            return;
        }

        $this->pdo->exec('PRAGMA foreign_keys = OFF');
        try {
            $this->pdo->beginTransaction();
            $this->pdo->exec('CREATE TABLE users_rebuilt (' . self::USERS_COLUMNS . ')');
            $this->pdo->exec('INSERT INTO users_rebuilt (sub, username, password_hash, name, email, email_verified, is_admin)
                SELECT sub, username, password_hash, name, email, email_verified, is_admin FROM users');
            $this->pdo->exec('DROP TABLE users');
            $this->pdo->exec('ALTER TABLE users_rebuilt RENAME TO users');
            if ($this->pdo->query('PRAGMA foreign_key_check')->fetchAll() !== []) {
                throw new \RuntimeException('Rebuilding the users table broke a foreign key; nothing was changed.');
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }
    }
}
