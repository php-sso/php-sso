<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Clients;

use PhpSso\Store\Clients;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;

#[CoversMethod(Clients::class, 'explainAccess')]
final class ExplainAccessTest extends TestCase
{
    public function testAnOpenClientAllowsEveryone(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $sub = $data->user('alice');

        // Act
        $result = $data->clients->explainAccess('app', $sub);

        // Assert
        $this->assertSame(['allowed' => true, 'reason' => 'The client is open to every user.'], $result);
    }

    public function testARestrictedClientAllowsAPersonLinkedDirectly(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app', restricted: true);
        $sub = $data->user('alice');
        $data->linkUser('app', $sub);

        // Act
        $result = $data->clients->explainAccess('app', $sub);

        // Assert
        $this->assertSame(['allowed' => true, 'reason' => 'The client is restricted, and this person is linked to it directly.'], $result);
    }

    public function testARestrictedClientAllowsAMemberOfALinkedGroupAndNamesTheGroup(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app', restricted: true);
        $sub = $data->user('alice');
        $data->linkGroup('app', $sub, 'staff');

        // Act
        $result = $data->clients->explainAccess('app', $sub);

        // Assert
        $this->assertSame(['allowed' => true, 'reason' => 'The client is restricted, and this person is in staff.'], $result);
    }

    public function testARestrictedClientRefusesEveryoneElse(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app', restricted: true);
        $sub = $data->user('alice');

        // Act
        $result = $data->clients->explainAccess('app', $sub);

        // Assert
        $this->assertFalse($result['allowed']);
    }
}
