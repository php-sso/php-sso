<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\ScimServer\ScimController;

use PhpSso\ScimServer\ScimController;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\ScimServer\Support;

#[CoversMethod(ScimController::class, 'handle')]
final class GroupsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }

    public function testACreatedGroupComesBackWith201AndItsMembers(): void
    {
        // Arrange
        $app = new Support();
        $alice = $this->json($app->controller->handle($app->request('POST', '/Users', Support::payload('alice@acme.com'))))['id'];

        // Act
        $response = $app->controller->handle($app->request('POST', '/Groups', ['displayName' => 'Engineering', 'externalId' => 'g1', 'members' => [['value' => $alice]]]));

        // Assert
        $body = $this->json($response);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('https://sso.test/scim/v2/Groups/' . $body['id'], $response->getHeaderLine('Location'));
        $this->assertSame([$alice], array_column($body['members'], 'value'));
        $this->assertSame('Alice Acme', $body['members'][0]['display']);
        $this->assertSame('https://sso.test/scim/v2/Users/' . $alice, $body['members'][0]['$ref']);
    }

    public function testFilteringByDisplayNameFindsTheGroup(): void
    {
        // Arrange
        $app = new Support();
        $app->controller->handle($app->request('POST', '/Groups', ['displayName' => 'Engineering']));
        $app->controller->handle($app->request('POST', '/Groups', ['displayName' => 'Sales']));

        // Act
        $found = $this->json($app->controller->handle($app->request('GET', '/Groups', null, '', ['filter' => 'displayName eq "ENGINEERING"'])));
        $none = $this->json($app->controller->handle($app->request('GET', '/Groups', null, '', ['filter' => 'displayName eq "Nope"'])));

        // Assert
        $this->assertSame([1, 'Engineering'], [$found['totalResults'], $found['Resources'][0]['displayName']]);
        $this->assertSame(0, $none['totalResults']);
    }

    public function testTheListOnlyShowsThisClientsGroups(): void
    {
        // Arrange
        $app = new Support();
        $app->controller->handle($app->request('POST', '/Groups', ['displayName' => 'Engineering']));
        $app->data->groups->create('Mine', '');

        // Act
        $list = $this->json($app->controller->handle($app->request('GET', '/Groups')));

        // Assert
        $this->assertSame([1, 'Engineering'], [$list['totalResults'], $list['Resources'][0]['displayName']]);
    }

    public function testPatchAddsAMemberAndDeleteReturns204(): void
    {
        // Arrange
        $app = new Support();
        $alice = $this->json($app->controller->handle($app->request('POST', '/Users', Support::payload('alice@acme.com'))))['id'];
        $group = $this->json($app->controller->handle($app->request('POST', '/Groups', ['displayName' => 'Engineering'])))['id'];

        // Act
        $patched = $app->controller->handle($app->request('PATCH', '/Groups/' . $group, ['Operations' => [['op' => 'Add', 'path' => 'members', 'value' => [['value' => $alice]]]]]));
        $deleted = $app->controller->handle($app->request('DELETE', '/Groups/' . $group));

        // Assert
        $this->assertSame([$alice], array_column($this->json($patched)['members'], 'value'));
        $this->assertSame(204, $deleted->getStatusCode());
        $this->assertSame(404, $app->controller->handle($app->request('GET', '/Groups/' . $group))->getStatusCode());
    }

    public function testAGroupOfYoursIsNotFoundByItsId(): void
    {
        // Arrange
        $app = new Support();
        $mine = $app->data->groups->create('Staff', '');

        // Act
        $response = $app->controller->handle($app->request('GET', '/Groups/' . $mine));

        // Assert
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testANameClashIsA409(): void
    {
        // Arrange
        $app = new Support();
        $app->data->groups->create('Admins', 'Mine');

        // Act
        $response = $app->controller->handle($app->request('POST', '/Groups', ['displayName' => 'Admins']));

        // Assert
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('uniqueness', $this->json($response)['scimType']);
    }

    public function testAFilterOnSomethingElseIsRefusedForGroups(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $response = $app->controller->handle($app->request('GET', '/Groups', null, '', ['filter' => 'userName eq "x"']));

        // Assert
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('invalidFilter', $this->json($response)['scimType']);
    }

    public function testTheDiscoveryEndpointsNowListGroupsToo(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $types = $this->json($app->controller->handle($app->request('GET', '/ResourceTypes')));
        $schemas = $this->json($app->controller->handle($app->request('GET', '/Schemas')));

        // Assert
        $this->assertSame(['/Users', '/Groups'], array_column($types['Resources'], 'endpoint'));
        $this->assertContains('urn:ietf:params:scim:schemas:core:2.0:Group', array_column($schemas['Resources'], 'id'));
    }

    public function testGroupRequestsAreRecordedToo(): void
    {
        // Arrange
        $app = new Support();
        $app->controller->handle($app->request('POST', '/Groups', ['displayName' => 'Engineering']));

        // Act
        $events = $app->events->recent($app->clientId);

        // Assert
        $this->assertSame([201], array_column($events, 'status'));
        $this->assertSame('Created group Engineering', $events[0]['detail']);
    }
}
