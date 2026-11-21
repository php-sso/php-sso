<?php

declare(strict_types=1);

namespace PhpSso\Store\Sqlite;

use PhpSso\Store\{ClientAuthFailures, Clients, Consents, DeviceFailures, Groups, HttpCache, Identities, IpRules, LoginAttempts, Maintenance, MfaFactors, Relations, Roles, SamlConnections, SamlRequests, ScimClients, ScimEvents, ScimGroups, ScimUsers, Scopes, Sessions, Settings, Storage, StorageOptions, Users};
use PhpSso\OAuthServer\Grant\AuthorizationCodeStore;
use PhpSso\OAuthServer\Grant\RefreshTokenStore;
use PhpSso\OAuthServer\Grant\DeviceAuthorizationStore;
use PhpSso\OAuthServer\Grant\RevokedAccessTokens;

/** The storage kept in one SQLite file. */
final class SqliteStorage implements Storage
{
    /** @var array<string, object> */
    private array $made = [];

    public function __construct(private Database $db, private StorageOptions $options)
    {
    }

    public static function open(string $path, StorageOptions $options): self
    {
        return new self(new Database($path), $options);
    }

    public function users(): Users
    {
        return $this->made[__FUNCTION__] ??= new SqliteUsers($this->db);
    }

    public function groups(): Groups
    {
        return $this->made[__FUNCTION__] ??= new SqliteGroups($this->db);
    }

    public function clients(): Clients
    {
        return $this->made[__FUNCTION__] ??= new SqliteClients($this->db);
    }

    public function roles(): Roles
    {
        return $this->made[__FUNCTION__] ??= new SqliteRoles($this->db);
    }

    public function relations(): Relations
    {
        return $this->made[__FUNCTION__] ??= new SqliteRelations($this->db);
    }

    public function scopes(): Scopes
    {
        return $this->made[__FUNCTION__] ??= new SqliteScopes($this->db, $this->options->builtinScopes);
    }

    public function sessions(): Sessions
    {
        return $this->made[__FUNCTION__] ??= new SqliteSessions($this->db, $this->options->sessionIdleSeconds);
    }

    public function loginAttempts(): LoginAttempts
    {
        return $this->made[__FUNCTION__] ??= new SqliteLoginAttempts($this->db);
    }

    public function ipRules(): IpRules
    {
        return $this->made[__FUNCTION__] ??= new SqliteIpRules($this->db);
    }

    public function consents(): Consents
    {
        return $this->made[__FUNCTION__] ??= new SqliteConsents($this->db);
    }

    public function settings(): Settings
    {
        return $this->made[__FUNCTION__] ??= new SqliteSettings($this->db);
    }

    public function mfaFactors(): MfaFactors
    {
        return $this->made[__FUNCTION__] ??= new SqliteMfaFactors($this->db);
    }

    public function identities(): Identities
    {
        return $this->made[__FUNCTION__] ??= new SqliteIdentities($this->db);
    }

    public function samlConnections(): SamlConnections
    {
        return $this->made[__FUNCTION__] ??= new SqliteSamlConnections($this->db);
    }

    public function samlRequests(): SamlRequests
    {
        return $this->made[__FUNCTION__] ??= new SqliteSamlRequests($this->db);
    }

    public function scimClients(): ScimClients
    {
        return $this->made[__FUNCTION__] ??= new SqliteScimClients($this->db);
    }

    public function scimEvents(): ScimEvents
    {
        return $this->made[__FUNCTION__] ??= new SqliteScimEvents($this->db);
    }

    public function scimUsers(): ScimUsers
    {
        return $this->made[__FUNCTION__] ??= new SqliteScimUsers($this->db);
    }

    public function scimGroups(): ScimGroups
    {
        return $this->made[__FUNCTION__] ??= new SqliteScimGroups($this->db);
    }

    public function httpCache(): HttpCache
    {
        return $this->made[__FUNCTION__] ??= new SqliteHttpCache($this->db);
    }

    public function clientAuthFailures(): ClientAuthFailures
    {
        return $this->made[__FUNCTION__] ??= new SqliteClientAuthFailures($this->db);
    }

    public function deviceFailures(): DeviceFailures
    {
        return $this->made[__FUNCTION__] ??= new SqliteDeviceFailures($this->db);
    }

    public function maintenance(): Maintenance
    {
        return $this->made[__FUNCTION__] ??= new SqliteMaintenance($this->db, $this->options->sessionIdleSeconds);
    }

    public function authorizationCodes(): AuthorizationCodeStore
    {
        return $this->made[__FUNCTION__] ??= new SqliteAuthorizationCodes($this->db);
    }

    public function refreshTokens(): RefreshTokenStore
    {
        return $this->made[__FUNCTION__] ??= new SqliteRefreshTokens($this->db);
    }

    public function deviceRequests(): DeviceAuthorizationStore
    {
        return $this->made[__FUNCTION__] ??= new SqliteDeviceRequests($this->db);
    }

    public function revokedAccessTokens(): RevokedAccessTokens
    {
        return $this->made[__FUNCTION__] ??= new SqliteRevokedAccessTokens($this->db);
    }

    public function enforceUniqueEmail(bool $unique): void
    {
        $this->db->enforceUniqueEmail($unique);
    }
}
