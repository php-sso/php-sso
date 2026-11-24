<?php

declare(strict_types=1);

namespace PhpSso\Backup;

/**
 * The tables a backup holds, in an order in which every table comes after the tables it points at, so rows can be loaded
 * one table at a time with the foreign keys checked. A test fails when a table is added to the schema and not here.
 */
final class BackupTables
{
    public const ORDER = [
        'client_auth_failures', 'clients', 'user_group', 'client_group', 'users', 'client_user', 'consents', 'device_failures', 'http_cache',
        'ip_rules', 'login_attempts', 'login_sessions', 'mfa_recovery_codes', 'oauth_codes', 'oauth_device_requests', 'oauth_refresh_tokens',
        'oauth_revoked_families', 'oauth_revoked_tokens', 'role', 'role_client', 'role_group', 'role_user', 'saml_connections', 'saml_domains',
        'saml_requests', 'scim_clients', 'scim_events', 'scim_groups', 'scim_users', 'scopes', 'session_clients', 'settings', 'user_consents',
        'user_group_member', 'user_identities', 'user_mfa', 'audit_log', 'logout_retries', 'password_resets',
        'user_ip_rules',
    ];

    /** Tables that belong to the database software or to the schema's own bookkeeping, not to the data. */
    public const NOT_DATA = ['schema_migrations'];

    /** The files of `var/keys` that a backup holds and a restore may write: nothing else is ever read from an archive. */
    public const KEY_FILE = '#^(private\.pem|public\.pem|mfa\.key|ring/\d+-[0-9a-f]{16}\.(pub|key))$#';
}
