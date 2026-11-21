<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\ScimServer;

use PhpSso\Auth\AccountPolicy;
use PhpSso\Auth\UserRules;
use PhpSso\Integration\Scim\StoreClientAuthenticator;
use PhpSso\Integration\Scim\StoreEventLog;
use PhpSso\Integration\Scim\StoreGroupDirectory;
use PhpSso\Integration\Scim\StoreManagedGroups;
use PhpSso\Integration\Scim\StoreManagedUsers;
use PhpSso\Integration\Scim\UserAccounts;
use PhpSso\ScimServer\ProvisioningClient;
use PhpSso\ScimServer\ScimController;
use PhpSso\ScimServer\ScimGroupService;
use PhpSso\ScimServer\ScimService;
use PhpSso\Store\ScimClients;
use PhpSso\Store\ScimEvents;
use PhpSso\Store\ScimGroups;
use PhpSso\Store\ScimUsers;
use PhpSso\Store\Settings;
use PhpSso\Store\Sessions;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use Psr\Http\Message\ServerRequestInterface;
use Tests\unit\PhpSso\Support\TestData;
use PhpSso\Store\Sql\SqlScimClients;
use PhpSso\Store\Sql\SqlScimEvents;
use PhpSso\Store\Sql\SqlScimGroups;
use PhpSso\Store\Sql\SqlScimUsers;
use PhpSso\Store\Sql\SqlSessions;
use PhpSso\Store\Sql\SqlSettings;

/** A SCIM client for acme.com on an in-memory database, with the service and controller built on it. */
final class Support
{
    public readonly TestData $data;
    public readonly ScimClients $clients;
    public readonly ScimUsers $scimUsers;
    public readonly ScimEvents $events;
    public readonly Sessions $sessions;
    public readonly ScimService $service;
    public readonly ScimGroups $scimGroups;
    public readonly ScimGroupService $groupService;
    public readonly ScimController $controller;
    public readonly StoreClientAuthenticator $authenticator;
    public readonly AccountPolicy $policy;
    public readonly int $clientId;
    public readonly string $token;

    public function __construct(string $onDelete = 'deactivate', bool $adopt = false, array $domains = ['acme.com'], string $identifier = 'email', string $groupPrefix = '', bool $adoptGroups = false, array $allowedDomains = [])
    {
        $this->data = new TestData();
        $this->clients = new SqlScimClients($this->data->db);
        $this->scimUsers = new SqlScimUsers($this->data->db);
        $this->events = new SqlScimEvents($this->data->db);
        $this->sessions = new SqlSessions($this->data->db, 3600);
        $this->scimGroups = new SqlScimGroups($this->data->db);
        $this->policy = new AccountPolicy(new SqlSettings($this->data->db), ['allowed_domains' => $allowedDomains]);
        $this->authenticator = new StoreClientAuthenticator($this->clients);
        $managedUsers = new StoreManagedUsers($this->scimUsers);
        $managedGroups = new StoreManagedGroups($this->scimGroups);
        $accounts = new UserAccounts($this->data->users, $this->sessions, new UserRules($this->data->users, $identifier), $this->policy);
        $this->service = new ScimService($managedUsers, $accounts, 'https://sso.test');
        $this->groupService = new ScimGroupService(new StoreGroupDirectory($this->data->groups), $managedGroups, $managedUsers, $accounts, 'https://sso.test');
        $this->controller = new ScimController($this->authenticator, $this->service, $this->groupService, $managedUsers, $managedGroups, new StoreEventLog($this->events), new ResponseFactory(), new StreamFactory(), 'https://sso.test');
        $created = $this->clients->create('Acme Okta', $domains, true, $onDelete, $adopt, $groupPrefix, $adoptGroups);
        $this->clientId = $created['id'];
        $this->token = $created['token'];
    }

    /** The client as the service sees it. */
    public function client(): ProvisioningClient
    {
        return $this->authenticator->authenticate($this->token) ?? throw new \LogicException('The test client is not switched on.');
    }

    /** @param array<string, mixed> $extra */
    public static function payload(string $userName = 'alice@acme.com', array $extra = []): array
    {
        return $extra + ['schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'], 'userName' => $userName, 'externalId' => 'ext-' . $userName, 'name' => ['givenName' => 'Alice', 'familyName' => 'Acme'], 'emails' => [['value' => $userName, 'primary' => true]], 'active' => true];
    }

    /** @param array<string, mixed>|null $body */
    public function request(string $method, string $path, ?array $body = null, ?string $token = '', array $query = []): ServerRequestInterface
    {
        $request = (new ServerRequest([], [], 'https://sso.test/scim/v2' . $path, $method, 'php://memory'))->withQueryParams($query);
        $token = $token === '' ? $this->token : $token;
        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }
        if ($body !== null) {
            $request->getBody()->write((string) json_encode($body));
            $request->getBody()->rewind();
        }

        return $request;
    }
}
