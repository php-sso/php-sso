<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\Saml;

use Tests\unit\PhpSso\Auth\Saml\IdpFixture;

use PhpSso\Integration\Saml\StoreConnectionRepository;
use PhpSso\Integration\Saml\StoreRequestStore;
use PhpSso\Saml\ServiceProvider\SignIn;
use PhpSso\Store\SamlConnections;
use PhpSso\Store\SamlRequests;
use Tests\integration\PhpSso\Support\TestData;
use PhpSso\Store\Sql\SqlSamlConnections;
use PhpSso\Store\Sql\SqlSamlRequests;

/** A company connection (acme.com) with a fake identity provider, and the service that talks to it. */
final class Support
{
    public readonly TestData $data;
    public readonly IdpFixture $idp;
    public readonly SamlConnections $connections;
    public readonly SamlRequests $requests;
    public readonly SignIn $service;
    public readonly int $id;

    public function __construct(string $baseUrl = 'https://sso.test', bool $enabled = true, array $domains = ['acme.com'], ?TestData $data = null)
    {
        $this->data = $data ?? new TestData();
        $this->idp = new IdpFixture();
        $this->connections = new SqlSamlConnections($this->data->db);
        $this->requests = new SqlSamlRequests($this->data->db);
        $this->service = new SignIn(new StoreConnectionRepository($this->connections), new StoreRequestStore($this->requests), $baseUrl);
        $this->id = $this->connections->create([
            'name' => 'Acme', 'idp_entity_id' => IdpFixture::ENTITY_ID, 'idp_sso_url' => IdpFixture::SSO_URL,
            'idp_certificate' => $this->idp->certificatePem(), 'enabled' => $enabled, 'create_users' => false, 'link_existing' => false,
        ], $domains);
    }

    /** A request we sent and have not had an answer to. */
    public function sent(string $requestId = '_request1', string $binding = 'binding-token', ?string $returnTo = '/account'): void
    {
        $this->requests->remember($requestId, $this->id, hash('sha256', $binding), $returnTo);
    }

    /** @param array<string, mixed> $options see IdpFixture::response() */
    public function answer(array $options = []): string
    {
        return $this->idp->response($options + ['acs' => $this->service->acsUrl($this->id), 'audience' => $this->service->entityId($this->id)]);
    }
}
