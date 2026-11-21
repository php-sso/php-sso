<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\ScimServer\ScimController;

use PhpSso\ScimServer\ScimController;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\ScimServer\Support;

#[CoversMethod(ScimController::class, 'handle')]
final class HandleTest extends TestCase
{
    /** @return array<string, mixed> */
    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }

    public function testWithoutAValidTokenNothingIsAnswered(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $none = $app->controller->handle($app->request('GET', '/Users', null, null));
        $wrong = $app->controller->handle($app->request('GET', '/Users', null, 'scim_wrong'));

        // Assert
        $this->assertSame([401, 401], [$none->getStatusCode(), $wrong->getStatusCode()]);
        $this->assertStringContainsString('Bearer', $none->getHeaderLine('WWW-Authenticate'));
        $this->assertSame('urn:ietf:params:scim:api:messages:2.0:Error', $this->json($none)['schemas'][0]);
    }

    public function testACreatedPersonComesBackWith201AndALocation(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $response = $app->controller->handle($app->request('POST', '/Users', Support::payload('alice@acme.com')));

        // Assert
        $body = $this->json($response);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertStringContainsString('application/scim+json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('https://sso.test/scim/v2/Users/' . $body['id'], $response->getHeaderLine('Location'));
        $this->assertSame('alice@acme.com', $body['userName']);
    }

    public function testAConflictIsA409WithTheScimErrorType(): void
    {
        // Arrange
        $app = new Support();
        $app->controller->handle($app->request('POST', '/Users', Support::payload('alice@acme.com')));

        // Act
        $response = $app->controller->handle($app->request('POST', '/Users', Support::payload('alice@acme.com')));

        // Assert
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(['409', 'uniqueness'], [$this->json($response)['status'], $this->json($response)['scimType']]);
    }

    public function testFilteringByUserNameFindsThePerson(): void
    {
        // Arrange
        $app = new Support();
        $app->controller->handle($app->request('POST', '/Users', Support::payload('alice@acme.com')));
        $app->controller->handle($app->request('POST', '/Users', Support::payload('bob@acme.com')));

        // Act
        $found = $app->controller->handle($app->request('GET', '/Users', null, '', ['filter' => 'userName eq "ALICE@acme.com"']));
        $missing = $app->controller->handle($app->request('GET', '/Users', null, '', ['filter' => 'userName eq "nobody@acme.com"']));

        // Assert
        $this->assertSame([1, 'alice@acme.com'], [$this->json($found)['totalResults'], $this->json($found)['Resources'][0]['userName']]);
        $this->assertSame([0, []], [$this->json($missing)['totalResults'], $this->json($missing)['Resources']]);
    }

    public function testListingIsPagedAndOnlyShowsThisClientsPeople(): void
    {
        // Arrange
        $app = new Support();
        foreach (['a', 'b', 'c'] as $name) {
            $app->controller->handle($app->request('POST', '/Users', Support::payload($name . '@acme.com')));
        }
        $app->data->user('someone-else');

        // Act
        $page = $this->json($app->controller->handle($app->request('GET', '/Users', null, '', ['startIndex' => '2', 'count' => '1'])));

        // Assert
        $this->assertSame([3, 2, 1], [$page['totalResults'], $page['startIndex'], $page['itemsPerPage']]);
        $this->assertSame('b@acme.com', $page['Resources'][0]['userName']);
    }

    public function testAFilterWeDoNotSupportIsRefusedClearly(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $response = $app->controller->handle($app->request('GET', '/Users', null, '', ['filter' => 'userName co "al"']));

        // Assert
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('invalidFilter', $this->json($response)['scimType']);
    }

    public function testPatchDeactivatesAndDeleteReturns204(): void
    {
        // Arrange
        $app = new Support();
        $id = $this->json($app->controller->handle($app->request('POST', '/Users', Support::payload('alice@acme.com'))))['id'];

        // Act
        $patched = $app->controller->handle($app->request('PATCH', '/Users/' . $id, ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => [['op' => 'Replace', 'path' => 'active', 'value' => 'False']]]));
        $deleted = $app->controller->handle($app->request('DELETE', '/Users/' . $id));

        // Assert
        $this->assertFalse($this->json($patched)['active']);
        $this->assertSame(204, $deleted->getStatusCode());
        $this->assertSame('', (string) $deleted->getBody());
    }

    public function testAPersonThatIsNotThisClientsIsNotFound(): void
    {
        // Arrange
        $app = new Support();
        $stranger = $app->data->user('stranger');

        // Act
        $response = $app->controller->handle($app->request('GET', '/Users/' . $stranger));

        // Assert
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testTheDiscoveryEndpointsAnswer(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $config = $this->json($app->controller->handle($app->request('GET', '/ServiceProviderConfig')));
        $types = $this->json($app->controller->handle($app->request('GET', '/ResourceTypes')));
        $schemas = $this->json($app->controller->handle($app->request('GET', '/Schemas')));

        // Assert
        $this->assertTrue($config['patch']['supported']);
        $this->assertFalse($config['bulk']['supported']);
        $this->assertSame('/Users', $types['Resources'][0]['endpoint']);
        $this->assertSame('urn:ietf:params:scim:schemas:core:2.0:User', $schemas['Resources'][0]['id']);
    }

    public function testAnUnknownPathIsNotFound(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $response = $app->controller->handle($app->request('POST', '/Bulk', ['Operations' => []]));

        // Assert
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testABodyThatIsNotJsonIsA400(): void
    {
        // Arrange
        $app = new Support();
        $request = $app->request('POST', '/Users');
        $request->getBody()->write('not json');
        $request->getBody()->rewind();

        // Act
        $response = $app->controller->handle($request);

        // Assert
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('invalidSyntax', $this->json($response)['scimType']);
    }

    public function testEveryRequestIsRecordedForTheAdministrator(): void
    {
        // Arrange
        $app = new Support();
        $app->controller->handle($app->request('POST', '/Users', Support::payload('alice@acme.com')));
        $app->controller->handle($app->request('POST', '/Users', Support::payload('alice@acme.com')));

        // Act
        $events = $app->events->recent($app->clientId);

        // Assert
        $this->assertSame([409, 201], array_column($events, 'status'));
        $this->assertSame('Created alice@acme.com', $events[1]['detail']);
        $this->assertStringContainsString('already exists', $events[0]['detail']);
    }

    public function testASwitchedOffClientIsRefusedAtOnce(): void
    {
        // Arrange
        $app = new Support();
        $client = $app->client();
        $app->clients->update($app->clientId, $client->name, $client->domains, false, 'deactivate', false);

        // Act
        $response = $app->controller->handle($app->request('GET', '/Users'));

        // Assert
        $this->assertSame(401, $response->getStatusCode());
    }
}
