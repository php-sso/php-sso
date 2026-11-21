<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql;

use PDO;

/**
 * The MySQL schema (MySQL 8.0.16 or later: it uses CHECK constraints and functional indexes). Text that must be unique or looked up is a VARCHAR; names SQLite compares ignoring case use a case-insensitive collation; every other text is compared exactly.
 *
 * Generated from the SQLite schema, which is the reference: a table or column added there is added here too (the
 * schema parity test fails until it is).
 */
final class MysqlSchema
{
    /** @var list<string> in the order they can be created (a table after the tables it points at) */
    private const STATEMENTS = [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS client_auth_failures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            client_id TEXT NOT NULL,
            ip VARCHAR(255) NOT NULL,
            created_at BIGINT NOT NULL,
            INDEX client_auth_failures_ip (ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS clients (
            client_id VARCHAR(255) PRIMARY KEY,
            name TEXT NOT NULL,
            secret_hash TEXT,
            confidential BIGINT NOT NULL,
            redirect_uris TEXT NOT NULL,
            grants TEXT NOT NULL,
            scopes TEXT,
            backchannel_logout_uri TEXT,
            post_logout_redirect_uris TEXT NOT NULL DEFAULT ('[]'),
            restricted BIGINT NOT NULL DEFAULT 0,
            ignore_general_ip_rules BIGINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS user_group (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) COLLATE utf8mb4_0900_as_ci NOT NULL UNIQUE,
            description TEXT NOT NULL DEFAULT ('')
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS client_group (
            client_id VARCHAR(255) NOT NULL,
            group_id BIGINT NOT NULL,
            PRIMARY KEY (client_id, group_id),
            FOREIGN KEY (client_id) REFERENCES clients (client_id) ON DELETE CASCADE,
            FOREIGN KEY (group_id) REFERENCES user_group (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS users (
            sub VARCHAR(255) PRIMARY KEY,
            username VARCHAR(255) COLLATE utf8mb4_0900_as_ci NOT NULL UNIQUE,
            password_hash TEXT,
            name TEXT NOT NULL,
            email VARCHAR(255) NOT NULL,
            email_verified BIGINT NOT NULL DEFAULT 0,
            is_admin BIGINT NOT NULL DEFAULT 0,
            must_change_password BIGINT NOT NULL DEFAULT 0,
            locked_at BIGINT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS client_user (
            client_id VARCHAR(255) NOT NULL,
            user_sub VARCHAR(255) NOT NULL,
            PRIMARY KEY (client_id, user_sub),
            FOREIGN KEY (client_id) REFERENCES clients (client_id) ON DELETE CASCADE,
            FOREIGN KEY (user_sub) REFERENCES users (sub) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS consents (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name TEXT NOT NULL,
            message TEXT NOT NULL DEFAULT (''),
            url TEXT,
            version BIGINT NOT NULL DEFAULT 1,
            enabled BIGINT NOT NULL DEFAULT 1,
            position BIGINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS device_failures (
            user_sub TEXT NOT NULL,
            created_at BIGINT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS http_cache (
            cache_key VARCHAR(512) PRIMARY KEY,
            body TEXT NOT NULL,
            fetched_at BIGINT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS ip_rules (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            client_id VARCHAR(255),
            type VARCHAR(16) NOT NULL CHECK (type IN ('allow', 'deny')),
            cidr VARCHAR(64) NOT NULL,
            note TEXT NOT NULL DEFAULT (''),
            created_at BIGINT NOT NULL,
            FOREIGN KEY (client_id) REFERENCES clients (client_id) ON DELETE CASCADE,
            UNIQUE INDEX ip_rules_unique ((COALESCE(client_id, '')), type, cidr)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS login_attempts (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username TEXT NOT NULL,
            user_sub VARCHAR(255),
            success BIGINT NOT NULL,
            ip TEXT NOT NULL DEFAULT (''),
            user_agent TEXT NOT NULL DEFAULT (''),
            created_at BIGINT NOT NULL,
            blocked BIGINT NOT NULL DEFAULT 0,
            throttled BIGINT NOT NULL DEFAULT 0,
            cleared BIGINT NOT NULL DEFAULT 0,
            factor TEXT NOT NULL DEFAULT ('password'),
            FOREIGN KEY (user_sub) REFERENCES users (sub) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS login_sessions (
            id VARCHAR(255) PRIMARY KEY,
            user_sub VARCHAR(255) NOT NULL,
            created_at BIGINT NOT NULL,
            last_seen BIGINT NOT NULL,
            ended_at BIGINT,
            ip TEXT NOT NULL DEFAULT (''),
            user_agent TEXT NOT NULL DEFAULT (''),
            impersonated_by TEXT,
            amr TEXT,
            FOREIGN KEY (user_sub) REFERENCES users (sub) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS mfa_recovery_codes (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_sub VARCHAR(255) NOT NULL,
            code_hash TEXT NOT NULL,
            used_at BIGINT,
            FOREIGN KEY (user_sub) REFERENCES users (sub) ON DELETE CASCADE,
            INDEX mfa_recovery_codes_user (user_sub)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS oauth_codes (
            code_hash VARCHAR(255) PRIMARY KEY,
            client_id TEXT NOT NULL,
            user_id TEXT NOT NULL,
            redirect_uri TEXT NOT NULL,
            scopes TEXT NOT NULL,
            code_challenge TEXT NOT NULL,
            nonce TEXT,
            session_id VARCHAR(255),
            family_id TEXT NOT NULL,
            expires_at BIGINT NOT NULL,
            used_at BIGINT,
            auth_time BIGINT,
            amr TEXT,
            INDEX oauth_codes_session (session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS oauth_device_requests (
            device_code_hash VARCHAR(255) PRIMARY KEY,
            user_code_hash VARCHAR(255) NOT NULL UNIQUE,
            client_id TEXT NOT NULL,
            scopes TEXT NOT NULL,
            family_id TEXT NOT NULL,
            expires_at BIGINT NOT NULL,
            interval_seconds BIGINT NOT NULL,
            status TEXT NOT NULL,
            user_id TEXT,
            session_id TEXT,
            last_polled_at BIGINT,
            consumed_at BIGINT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS oauth_refresh_tokens (
            token_hash VARCHAR(255) PRIMARY KEY,
            family_id TEXT NOT NULL,
            client_id TEXT NOT NULL,
            user_id TEXT NOT NULL,
            scopes TEXT NOT NULL,
            session_id VARCHAR(255),
            expires_at BIGINT NOT NULL,
            used_at BIGINT,
            auth_time BIGINT,
            amr TEXT,
            INDEX oauth_refresh_tokens_session (session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS oauth_revoked_families (
            family_id VARCHAR(255) PRIMARY KEY,
            revoked_at BIGINT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS oauth_revoked_tokens (
            token_id VARCHAR(255) PRIMARY KEY,
            expires_at BIGINT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS role (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) COLLATE utf8mb4_0900_as_ci NOT NULL UNIQUE,
            description TEXT NOT NULL DEFAULT (''),
            all_clients BIGINT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS role_client (
            role_id BIGINT NOT NULL,
            client_id VARCHAR(255) NOT NULL,
            PRIMARY KEY (role_id, client_id),
            FOREIGN KEY (role_id) REFERENCES role (id) ON DELETE CASCADE,
            FOREIGN KEY (client_id) REFERENCES clients (client_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS role_group (
            role_id BIGINT NOT NULL,
            group_id BIGINT NOT NULL,
            PRIMARY KEY (role_id, group_id),
            FOREIGN KEY (role_id) REFERENCES role (id) ON DELETE CASCADE,
            FOREIGN KEY (group_id) REFERENCES user_group (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS role_user (
            role_id BIGINT NOT NULL,
            user_sub VARCHAR(255) NOT NULL,
            PRIMARY KEY (role_id, user_sub),
            FOREIGN KEY (role_id) REFERENCES role (id) ON DELETE CASCADE,
            FOREIGN KEY (user_sub) REFERENCES users (sub) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS saml_connections (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name TEXT NOT NULL,
            idp_entity_id TEXT NOT NULL,
            idp_sso_url TEXT NOT NULL,
            idp_certificate TEXT NOT NULL,
            enabled BIGINT NOT NULL DEFAULT 1,
            create_users BIGINT NOT NULL DEFAULT 0,
            link_existing BIGINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS saml_domains (
            domain VARCHAR(255) COLLATE utf8mb4_0900_as_ci PRIMARY KEY,
            connection_id BIGINT NOT NULL,
            FOREIGN KEY (connection_id) REFERENCES saml_connections (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS saml_requests (
            request_id VARCHAR(255) PRIMARY KEY,
            connection_id BIGINT NOT NULL,
            binding_hash TEXT NOT NULL,
            return_to TEXT,
            created_at BIGINT NOT NULL,
            FOREIGN KEY (connection_id) REFERENCES saml_connections (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS scim_clients (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name TEXT NOT NULL,
            token_hash VARCHAR(255) NOT NULL UNIQUE,
            domains TEXT NOT NULL DEFAULT ('[]'),
            enabled BIGINT NOT NULL DEFAULT 1,
            on_delete TEXT NOT NULL DEFAULT ('deactivate'),
            adopt_existing BIGINT NOT NULL DEFAULT 0,
            created_at BIGINT NOT NULL,
            last_used_at BIGINT,
            group_prefix TEXT NOT NULL DEFAULT (''),
            adopt_groups BIGINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS scim_events (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            client_id BIGINT NOT NULL,
            at BIGINT NOT NULL,
            method TEXT NOT NULL,
            path TEXT NOT NULL,
            status BIGINT NOT NULL,
            detail TEXT NOT NULL DEFAULT (''),
            FOREIGN KEY (client_id) REFERENCES scim_clients (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS scim_groups (
            group_id BIGINT PRIMARY KEY,
            client_id BIGINT NOT NULL,
            display_name VARCHAR(255) COLLATE utf8mb4_0900_as_ci NOT NULL,
            external_id VARCHAR(255),
            FOREIGN KEY (group_id) REFERENCES user_group (id) ON DELETE CASCADE,
            FOREIGN KEY (client_id) REFERENCES scim_clients (id) ON DELETE CASCADE,
            UNIQUE INDEX scim_groups_display_name (client_id, display_name),
            UNIQUE INDEX scim_groups_external_id (client_id, external_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS scim_users (
            user_sub VARCHAR(255) PRIMARY KEY,
            client_id BIGINT NOT NULL,
            user_name VARCHAR(255) COLLATE utf8mb4_0900_as_ci NOT NULL,
            external_id VARCHAR(255),
            given_name TEXT,
            family_name TEXT,
            FOREIGN KEY (user_sub) REFERENCES users (sub) ON DELETE CASCADE,
            FOREIGN KEY (client_id) REFERENCES scim_clients (id) ON DELETE CASCADE,
            UNIQUE INDEX scim_users_external_id (client_id, external_id),
            UNIQUE INDEX scim_users_user_name (client_id, user_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS scopes (
            name VARCHAR(255) PRIMARY KEY,
            description TEXT NOT NULL DEFAULT (''),
            created_at BIGINT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS session_clients (
            session_id VARCHAR(255) NOT NULL,
            client_id VARCHAR(255) NOT NULL,
            PRIMARY KEY (session_id, client_id),
            FOREIGN KEY (session_id) REFERENCES login_sessions (id) ON DELETE CASCADE,
            FOREIGN KEY (client_id) REFERENCES clients (client_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS settings (
            "key" VARCHAR(255) PRIMARY KEY,
            "value" TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS user_consents (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_sub VARCHAR(255) NOT NULL,
            consent_id BIGINT NOT NULL,
            version BIGINT NOT NULL,
            accepted_at BIGINT NOT NULL,
            ip TEXT,
            name TEXT NOT NULL,
            message TEXT NOT NULL,
            url TEXT,
            withdrawn_at BIGINT,
            FOREIGN KEY (user_sub) REFERENCES users (sub) ON DELETE CASCADE,
            FOREIGN KEY (consent_id) REFERENCES consents (id) ON DELETE RESTRICT,
            INDEX user_consents_lookup (user_sub, consent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS user_group_member (
            user_sub VARCHAR(255) NOT NULL,
            group_id BIGINT NOT NULL,
            PRIMARY KEY (user_sub, group_id),
            FOREIGN KEY (user_sub) REFERENCES users (sub) ON DELETE CASCADE,
            FOREIGN KEY (group_id) REFERENCES user_group (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS user_identities (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_sub VARCHAR(255) NOT NULL,
            provider VARCHAR(64) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            email TEXT,
            created_at BIGINT NOT NULL,
            last_login_at BIGINT,
            UNIQUE (provider, subject),
            FOREIGN KEY (user_sub) REFERENCES users (sub) ON DELETE CASCADE,
            INDEX user_identities_user (user_sub)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS user_mfa (
            user_sub VARCHAR(255) PRIMARY KEY,
            secret TEXT NOT NULL,
            last_counter BIGINT NOT NULL DEFAULT 0,
            created_at BIGINT NOT NULL,
            FOREIGN KEY (user_sub) REFERENCES users (sub) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        SQL,
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /** Creates the tables that are not there yet. */
    public function install(): void
    {
        foreach (self::STATEMENTS as $statement) {
            $this->pdo->exec($statement);
        }
    }
}
