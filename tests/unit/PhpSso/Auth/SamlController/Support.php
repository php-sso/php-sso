<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\SamlController;

use PhpSso\Auth\SamlController;
use PhpSso\Auth\Social\AccountMatcher;
use PhpSso\Auth\AccountPolicy;
use PhpSso\Net\ClientIp;
use PhpSso\Store\Identities;
use PhpSso\Store\Settings;
use Laminas\Diactoros\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Tests\unit\PhpSso\Auth\LoginController\Support as LoginSupport;
use Tests\unit\PhpSso\Auth\Saml\Support as SamlSupport;

/** The SAML controller on an in-memory database, with a company (acme.com) and its fake identity provider. */
final class Support
{
    public readonly LoginSupport $login;
    public readonly SamlSupport $saml;
    public readonly SamlController $controller;
    public readonly Identities $identities;
    public readonly AccountPolicy $policy;

    /** @param array<string, mixed> $policy the allowed-domains rule and the like */
    public function __construct(string $baseUrl = 'https://sso.test', bool $enabled = true, array $policy = [], string $mfaPolicy = 'optional')
    {
        $this->login = new LoginSupport([], $mfaPolicy);
        $this->saml = new SamlSupport($baseUrl, $enabled, ['acme.com'], $this->login->data);
        $this->identities = new Identities($this->login->data->db);
        $this->policy = new AccountPolicy(new Settings($this->login->data->db), $policy);
        $this->controller = new SamlController(
            $this->saml->service,
            $this->saml->connections,
            new AccountMatcher($this->identities, $this->login->data->users),
            $this->policy,
            $this->login->flow,
            $this->login->mfa,
            new ClientIp([]),
        );
        $_SESSION = [];
    }

    /** Lets the connection connect and create people. */
    public function allow(bool $link, bool $create): void
    {
        $connection = $this->saml->connections->find($this->saml->id);
        $this->saml->connections->update($this->saml->id, [
            'name' => $connection['name'], 'idp_entity_id' => $connection['idp_entity_id'], 'idp_sso_url' => $connection['idp_sso_url'],
            'idp_certificate' => $connection['idp_certificate'], 'enabled' => (bool) $connection['enabled'], 'create_users' => $create, 'link_existing' => $link,
        ], $connection['domains']);
    }

    /** The provider's answer arriving at the reply address, as the browser posts it. */
    public function post(string $answer, ?string $binding = 'binding-token'): ServerRequestInterface
    {
        $request = (new ServerRequest(['REMOTE_ADDR' => '203.0.113.9'], [], '/saml/' . $this->saml->id . '/acs', 'POST'))->withParsedBody(['SAMLResponse' => $answer]);

        return $binding === null ? $request : $request->withCookieParams(['sso_saml' => $binding]);
    }
}
