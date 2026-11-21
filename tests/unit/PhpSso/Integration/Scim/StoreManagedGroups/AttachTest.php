<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Integration\Scim\StoreManagedGroups;

use PhpSso\Integration\Scim\StoreManagedGroups;
use PhpSso\ScimServer\UniquenessViolation;
use PhpSso\Store\ScimGroups;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\ScimServer\Support;

#[CoversMethod(StoreManagedGroups::class, 'attach')]
final class AttachTest extends TestCase
{
    public function testItRecordsThatTheClientManagesTheGroup(): void
    {
        // Arrange
        $app = new Support();
        $managed = new StoreManagedGroups(new ScimGroups($app->data->db));
        $id = $app->data->groups->create('engineering', 'Mine');

        // Act
        $managed->attach($app->clientId, $id, 'Engineering', 'grp-1');

        // Assert
        $group = $managed->find($app->clientId, $id);
        $this->assertNotNull($group);
        $this->assertSame(['engineering', 'Engineering', 'grp-1'], [$group->name, $group->displayName, $group->externalId]);
        $this->assertTrue($managed->isManaged($id));
    }

    public function testATakenDisplayNameIsAUniquenessViolationNotADatabaseError(): void
    {
        // Arrange
        $app = new Support();
        $managed = new StoreManagedGroups(new ScimGroups($app->data->db));
        $managed->attach($app->clientId, $app->data->groups->create('one', ''), 'Same', null);
        $second = $app->data->groups->create('two', '');

        // Act
        $attach = fn () => $managed->attach($app->clientId, $second, 'Same', null);

        // Assert
        $this->expectException(UniquenessViolation::class);
        $attach();
    }
}
