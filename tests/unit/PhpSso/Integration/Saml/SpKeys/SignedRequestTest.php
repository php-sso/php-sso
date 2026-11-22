<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Integration\Saml\SpKeys;

use PhpSso\Integration\Saml\SpKeys;
use PhpSso\Integration\Saml\StoreConnectionRepository;
use PhpSso\Integration\Saml\StoreRequestStore;
use PhpSso\Saml\ServiceProvider\SignIn;
use PhpSso\Store\Sql\SqlSamlConnections;
use PhpSso\Store\Sql\SqlSamlRequests;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;
use Tests\unit\PhpSso\Support\UsesBackupFiles;

#[CoversMethod(SpKeys::class, 'current')]
final class SignedRequestTest extends TestCase
{
    use UsesBackupFiles;

    /** The whole way: the administrator sets a connection to sign, the key is made, and the request to the provider verifies with the published certificate. */
    public function testAConnectionSetToSignSendsARequestThatVerifiesWithThePublishedCertificate(): void
    {
        // Arrange
        $data = new TestData();
        $connections = new SqlSamlConnections($data->db);
        $id = $connections->create(['name' => 'Acme', 'idp_entity_id' => 'https://idp.acme.test/e', 'idp_sso_url' => 'https://idp.acme.test/sso', 'idp_certificate' => 'x', 'enabled' => true, 'create_users' => false, 'link_existing' => false, 'sign_requests' => true], ['acme.com']);
        $keys = new SpKeys($this->work . '/keys', 'sso.example.com');
        $keys->ensure();
        $service = new SignIn(new StoreConnectionRepository($connections), new StoreRequestStore(new SqlSamlRequests($data->db)), 'https://sso.example.com', $keys->current());

        // Act
        $url = $service->begin($id, '/account')->url;

        // Assert
        $query = (string) parse_url($url, PHP_URL_QUERY);
        preg_match('/^(SAMLRequest=[^&]*(?:&RelayState=[^&]*)?&SigAlg=[^&]*)&Signature=([^&]*)$/', $query, $m);
        $this->assertCount(3, $m);
        $certificate = $keys->status()['current']['certificate'] ?? '';
        $this->assertSame(1, openssl_verify($m[1], (string) base64_decode(urldecode($m[2]), true), $certificate, OPENSSL_ALGO_SHA256));
        $this->assertStringContainsString('AuthnRequestsSigned="true"', $service->metadataXml($id));
    }
}
