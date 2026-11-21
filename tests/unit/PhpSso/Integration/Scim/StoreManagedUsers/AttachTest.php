<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Integration\Scim\StoreManagedUsers;

use PhpSso\Integration\Scim\StoreManagedUsers;
use PhpSso\ScimServer\UniquenessViolation;
use PhpSso\Store\ScimUsers;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\ScimServer\Support;
use PhpSso\Store\Sqlite\SqliteScimUsers;

#[CoversMethod(StoreManagedUsers::class, 'attach')]
final class AttachTest extends TestCase
{
    public function testItRecordsThatTheClientManagesThePerson(): void
    {
        // Arrange
        $app = new Support();
        $managed = new StoreManagedUsers(new SqliteScimUsers($app->data->db));
        $sub = $app->data->user('alice');

        // Act
        $managed->attach($app->clientId, $sub, 'alice@acme.com', 'ext-1', 'Alice', 'Acme');

        // Assert
        $user = $managed->find($app->clientId, $sub);
        $this->assertNotNull($user);
        $this->assertSame(['alice@acme.com', 'ext-1', 'Alice', 'Acme'], [$user->userName, $user->externalId, $user->givenName, $user->familyName]);
        $this->assertTrue($managed->isManaged($sub));
    }

    public function testATakenUserNameIsAUniquenessViolationNotADatabaseError(): void
    {
        // Arrange
        $app = new Support();
        $managed = new StoreManagedUsers(new SqliteScimUsers($app->data->db));
        $managed->attach($app->clientId, $app->data->user('alice'), 'shared-name', null, null, null);
        $bob = $app->data->user('bob');

        // Act
        $attach = fn () => $managed->attach($app->clientId, $bob, 'shared-name', null, null, null);

        // Assert
        $this->expectException(UniquenessViolation::class);
        $attach();
    }
}
