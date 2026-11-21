<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql;

use PhpSso\Store\{ClientAuthFailures, Clients, Consents, DeviceFailures, Groups, HttpCache, Identities, IpRules, LoginAttempts, Maintenance, MfaFactors, Relations, Roles, SamlConnections, SamlRequests, ScimClients, ScimEvents, ScimGroups, ScimUsers, Scopes, Sessions, Settings, Storage, StorageOptions, Users};
use PhpSso\OAuthServer\Grant\AuthorizationCodeStore;
use PhpSso\OAuthServer\Grant\RefreshTokenStore;
use PhpSso\OAuthServer\Grant\DeviceAuthorizationStore;
use PhpSso\OAuthServer\Grant\RevokedAccessTokens;

/** The storage kept in one SQLite file. */
final class SqlStorage implements Storage
{
    /** @var array<string, object> */
    private array $made = [];

    public function __construct(private Connection $db, private StorageOptions $options)
    {
    }

    public function users(): Users
    {
        return $this->made[__FUNCTION__] ??= new SqlUsers($this->db);
    }

    public function groups(): Groups
    {
        return $this->made[__FUNCTION__] ??= new SqlGroups($this->db);
    }

    public function clients(): Clients
    {
        return $this->made[__FUNCTION__] ??= new SqlClients($this->db);
    }

    public function roles(): Roles
    {
        return $this->made[__FUNCTION__] ??= new SqlRoles($this->db);
    }

    public function relations(): Relations
    {
        return $this->made[__FUNCTION__] ??= new SqlRelations($this->db);
    }

    public function scopes(): Scopes
    {
        return $this->made[__FUNCTION__] ??= new SqlScopes($this->db, $this->options->builtinScopes);
    }

    public function sessions(): Sessions
    {
        return $this->made[__FUNCTION__] ??= new SqlSessions($this->db, $this->options->sessionIdleSeconds);
    }

    public function loginAttempts(): LoginAttempts
    {
        return $this->made[__FUNCTION__] ??= new SqlLoginAttempts($this->db);
    }

    public function ipRules(): IpRules
    {
        return $this->made[__FUNCTION__] ??= new SqlIpRules($this->db);
    }

    public function consents(): Consents
    {
        return $this->made[__FUNCTION__] ??= new SqlConsents($this->db);
    }

    public function settings(): Settings
    {
        return $this->made[__FUNCTION__] ??= new SqlSettings($this->db);
    }

    public function mfaFactors(): MfaFactors
    {
        return $this->made[__FUNCTION__] ??= new SqlMfaFactors($this->db);
    }

    public function identities(): Identities
    {
        return $this->made[__FUNCTION__] ??= new SqlIdentities($this->db);
    }

    public function samlConnections(): SamlConnections
    {
        return $this->made[__FUNCTION__] ??= new SqlSamlConnections($this->db);
    }

    public function samlRequests(): SamlRequests
    {
        return $this->made[__FUNCTION__] ??= new SqlSamlRequests($this->db);
    }

    public function scimClients(): ScimClients
    {
        return $this->made[__FUNCTION__] ??= new SqlScimClients($this->db);
    }

    public function scimEvents(): ScimEvents
    {
        return $this->made[__FUNCTION__] ??= new SqlScimEvents($this->db);
    }

    public function scimUsers(): ScimUsers
    {
        return $this->made[__FUNCTION__] ??= new SqlScimUsers($this->db);
    }

    public function scimGroups(): ScimGroups
    {
        return $this->made[__FUNCTION__] ??= new SqlScimGroups($this->db);
    }

    public function httpCache(): HttpCache
    {
        return $this->made[__FUNCTION__] ??= new SqlHttpCache($this->db);
    }

    public function clientAuthFailures(): ClientAuthFailures
    {
        return $this->made[__FUNCTION__] ??= new SqlClientAuthFailures($this->db);
    }

    public function deviceFailures(): DeviceFailures
    {
        return $this->made[__FUNCTION__] ??= new SqlDeviceFailures($this->db);
    }

    public function maintenance(): Maintenance
    {
        return $this->made[__FUNCTION__] ??= new SqlMaintenance($this->db, $this->options->sessionIdleSeconds);
    }

    public function authorizationCodes(): AuthorizationCodeStore
    {
        return $this->made[__FUNCTION__] ??= new SqlAuthorizationCodes($this->db);
    }

    public function refreshTokens(): RefreshTokenStore
    {
        return $this->made[__FUNCTION__] ??= new SqlRefreshTokens($this->db);
    }

    public function deviceRequests(): DeviceAuthorizationStore
    {
        return $this->made[__FUNCTION__] ??= new SqlDeviceRequests($this->db);
    }

    public function revokedAccessTokens(): RevokedAccessTokens
    {
        return $this->made[__FUNCTION__] ??= new SqlRevokedAccessTokens($this->db);
    }

    public function enforceUniqueEmail(bool $unique): void
    {
        $this->db->enforceUniqueEmail($unique);
    }
}
