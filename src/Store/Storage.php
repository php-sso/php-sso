<?php

declare(strict_types=1);

namespace PhpSso\Store;

use PhpSso\OAuthServer\Grant\AuthorizationCodeStore;
use PhpSso\OAuthServer\Grant\DeviceAuthorizationStore;
use PhpSso\OAuthServer\Grant\RefreshTokenStore;
use PhpSso\OAuthServer\Grant\RevokedAccessTokens;

/**
 * Everything the server keeps, behind one door. The app asks this for its stores and never learns what is underneath, so a
 * different backend is a new implementation of this interface (and of the store interfaces it hands out), chosen in the
 * `storage` config. Every store is created once and shared.
 */
interface Storage
{
    public function users(): Users;

    public function groups(): Groups;

    public function clients(): Clients;

    public function roles(): Roles;

    public function relations(): Relations;

    public function scopes(): Scopes;

    public function sessions(): Sessions;

    public function loginAttempts(): LoginAttempts;

    public function ipRules(): IpRules;

    public function consents(): Consents;

    public function settings(): Settings;

    public function mfaFactors(): MfaFactors;

    public function identities(): Identities;

    public function samlConnections(): SamlConnections;

    public function samlRequests(): SamlRequests;

    public function scimClients(): ScimClients;

    public function scimEvents(): ScimEvents;

    public function scimUsers(): ScimUsers;

    public function scimGroups(): ScimGroups;

    public function httpCache(): HttpCache;

    public function clientAuthFailures(): ClientAuthFailures;

    public function deviceFailures(): DeviceFailures;

    public function maintenance(): Maintenance;

    public function authorizationCodes(): AuthorizationCodeStore;

    public function refreshTokens(): RefreshTokenStore;

    public function deviceRequests(): DeviceAuthorizationStore;

    public function revokedAccessTokens(): RevokedAccessTokens;

    /** Backing up everything, and loading a backup (see bin/backup and bin/restore). */
    public function backup(): Backup;

    /**
     * Makes email addresses unique (ignoring case), or stops requiring it. Signing in by email needs it. Fails, naming the
     * addresses, when the data already has duplicates.
     */
    public function enforceUniqueEmail(bool $unique): void;
}
