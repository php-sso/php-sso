<?php

declare(strict_types=1);

return [
    'issuer' => getenv('SSO_PUBLIC_URL') ?: 'http://localhost:9000',
    // Where the data lives: SSO_DB_DRIVER is `sqlite` (the default: one file, SSO_DB_PATH), `mysql` (8.0.19 or later) or `pgsql`
    // (13 or later), which also need SSO_DB_HOST, SSO_DB_NAME, SSO_DB_USER and SSO_DB_PASSWORD (and SSO_DB_PORT when it is not the
    // usual one). The tables are created on first start. See PhpSso\Store\StorageFactory.
    'storage' => [
        'driver' => getenv('SSO_DB_DRIVER') ?: 'sqlite',
        'path' => getenv('SSO_DB_PATH') ?: dirname(__DIR__) . '/var/sso.sqlite',
        'host' => getenv('SSO_DB_HOST') ?: '',
        'port' => (int) (getenv('SSO_DB_PORT') ?: 0) ?: null,
        'database' => getenv('SSO_DB_NAME') ?: '',
        'username' => getenv('SSO_DB_USER') ?: '',
        'password' => getenv('SSO_DB_PASSWORD') ?: '',
    ],
    'keys_dir' => dirname(__DIR__) . '/var/keys',
    'twig_cache' => dirname(__DIR__) . '/var/cache/twig',

    // Theming of the pages people see (login, profile, errors). Files in theme_dir override the built-in ones.
    'theme_dir' => getenv('SSO_THEME_DIR') ?: '/theme',
    'app_name' => getenv('SSO_APP_NAME') ?: 'Single sign-on',
    'debug' => filter_var(getenv('SSO_DEBUG') ?: false, FILTER_VALIDATE_BOOL), // theme errors are shown instead of falling back

    // Reverse proxies / load balancers (addresses or CIDR ranges, comma separated in SSO_TRUSTED_PROXIES) whose
    // X-Forwarded-For header is believed. Leave empty when the app is reached directly: the header is then ignored.
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', getenv('SSO_TRUSTED_PROXIES') ?: '')))),

    'login' => [
        // What people type to sign in: 'username', 'email' or 'username_or_email'. The email modes need every user
        // to have a different email address (an address shared by two people matches no one).
        'identifier' => getenv('SSO_LOGIN_IDENTIFIER') ?: 'username',
        // Failed password attempts allowed within the window, per identifier (or the account it names) and per address.
        // Past either limit, sign-in is refused until old failures leave the window. These are the starting values:
        // an administrator can change them at /admin/security, and what is saved there wins.
        'throttle' => ['enabled' => true, 'per_identifier' => 5, 'per_ip' => 20, 'window_seconds' => 900],
        // Two-step verification with an authenticator app. Anyone who has set it up is always asked for a code. The policy
        // says who must set it up: 'optional', 'admins' or 'everyone'. An administrator can change it at /admin/security.
        'mfa' => ['policy' => getenv('SSO_MFA_POLICY') ?: 'optional'],
        // Sign in with an account at another provider. A provider is offered once it has a client id and secret.
        // Register `<SSO_PUBLIC_URL>/login/social/<key>/callback` as its redirect address (shown at /admin/security).
        // `type` is 'oidc' (needs `issuer`; Google, Microsoft, Okta, Keycloak...) or 'github'. Add more entries as needed.
        'social' => [
            'providers' => [
                'google' => ['type' => 'oidc', 'label' => 'Google', 'issuer' => 'https://accounts.google.com', 'client_id' => getenv('SSO_GOOGLE_CLIENT_ID') ?: '', 'client_secret' => getenv('SSO_GOOGLE_CLIENT_SECRET') ?: ''],
                'github' => ['type' => 'github', 'label' => 'GitHub', 'client_id' => getenv('SSO_GITHUB_CLIENT_ID') ?: '', 'client_secret' => getenv('SSO_GITHUB_CLIENT_SECRET') ?: ''],
                'oidc' => ['type' => 'oidc', 'label' => getenv('SSO_OIDC_LABEL') ?: 'Single sign-on', 'issuer' => getenv('SSO_OIDC_ISSUER') ?: '', 'client_id' => getenv('SSO_OIDC_CLIENT_ID') ?: '', 'client_secret' => getenv('SSO_OIDC_CLIENT_SECRET') ?: ''],
            ],
            // What happens for a provider account nobody has connected yet. Both are off until allowed (and an
            // administrator can change them at /admin/security): see PhpSso\Auth\Social\AccountPolicy.
            'link_by_verified_email' => false,
            'create_users' => false,
            'allowed_domains' => [],
        ],
    ],

    'session_idle_seconds' => 3600, // an SSO browser session with no activity for this long is signed out
    // Lifetimes in seconds.
    'auth_code_ttl' => 60,
    'access_token_ttl' => 3600,     // also the lifetime of the ID token
    'refresh_token_ttl' => 2592000, // 30 days; every refresh starts a new token

    // PKCE is always required of public clients. Confidential clients need it too, unless this is switched off with
    // SSO_PKCE_OPTIONAL_FOR_CONFIDENTIAL_CLIENTS=1. Leave it on in production: it exists so the OpenID conformance
    // suite, whose tests do not send PKCE, can run against a test instance (see conformance/README.md).
    'require_pkce_for_confidential_clients' => !filter_var(getenv('SSO_PKCE_OPTIONAL_FOR_CONFIDENTIAL_CLIENTS') ?: false, FILTER_VALIDATE_BOOL),

    // Failed client authentications (a wrong client secret) at /token, /introspect, /revoke and /device_authorization.
    // Past `per_client_ip` failures for one client from one address, or `per_ip` from one address for any client, within
    // the window, that address gets 429 until old failures leave the window. Counting per client AND address means
    // nobody can lock an app out by spamming its client id from elsewhere. Set `enabled` to false to switch it off.
    'client_auth_throttle' => ['enabled' => true, 'per_client_ip' => 10, 'per_ip' => 30, 'window_seconds' => 900],

    // Scopes and the claims each one unlocks (sub is always included).
    'scopes' => [
        'openid' => [],
        'profile' => ['name'],
        'email' => ['email', 'email_verified'],
        'groups' => ['groups'],
        // The user's roles, as `roles` in the access token, ID token and userinfo. Only the roles available in the client
        // that asks are included, so they are not in this list of claims: see PhpSso\Claims::clientClaimsFor().
        'roles' => [],
    ],

    // Inserted once, when the database has no users. Manage everything at /admin afterwards.
    // SSO_DEMO_DATA=1 adds a demo user, group and client with well-known passwords: local development only.
    // Without it, only the administrator is created, and ADMIN_PASSWORD must be set.
    'seed' => filter_var(getenv('SSO_DEMO_DATA') ?: false, FILTER_VALIDATE_BOOL)
        ? [
            'groups' => ['staff' => 'All employees'],
            'users' => [
                'admin' => ['password' => getenv('ADMIN_PASSWORD') ?: 'admin123', 'name' => 'Administrator', 'email' => 'admin@example.com', 'is_admin' => true],
                'alice' => ['password' => 'password123', 'name' => 'Alice Example', 'email' => 'alice@example.com', 'groups' => ['staff']],
            ],
            // Roles are defined once. `clients` lists the client ids that receive the role; without it every client does.
            'roles' => [
                'editor' => ['description' => 'May edit content', 'groups' => ['staff']],
                'admin' => ['description' => 'May manage the app', 'users' => ['admin'], 'clients' => ['demo-client']],
            ],
            // secret => null makes a public client (no secret, PKCE required).
            'clients' => [
                'demo-client' => [
                    'name' => 'Demo client',
                    'secret' => 'demo-secret',
                    'redirect_uris' => ['http://localhost:9001/callback'],
                    'grants' => ['authorization_code', 'refresh_token'],
                    'backchannel_logout_uri' => 'http://client:9001/backchannel-logout', // reached from inside the compose network
                    'post_logout_redirect_uris' => ['http://localhost:9001/'],
                ],
                // A second app, to try single sign-on between two of them (docker compose service `client2`).
                'demo-client-2' => [
                    'name' => 'Demo client 2',
                    'secret' => 'demo-secret-2',
                    'redirect_uris' => ['http://localhost:9002/callback'],
                    'grants' => ['authorization_code', 'refresh_token'],
                    'backchannel_logout_uri' => 'http://client2:9002/backchannel-logout',
                    'post_logout_redirect_uris' => ['http://localhost:9002/'],
                ],
            ],
        ]
        : [
            'groups' => [],
            'users' => [
                'admin' => ['password' => getenv('ADMIN_PASSWORD') ?: '', 'name' => 'Administrator', 'email' => getenv('ADMIN_EMAIL') ?: 'admin@example.com', 'is_admin' => true],
            ],
            'clients' => [],
        ],
];
