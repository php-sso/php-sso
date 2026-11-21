<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\SessionLinks;

use PhpSso\Store\Sql\Connection;
use PhpSso\OAuth\SessionLinks;
use PhpSso\Store\Sessions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use PhpSso\Store\Sql\SqlSessions;

#[CoversMethod(SessionLinks::class, 'clientsOf')]
final class ClientsOfTest extends TestCase
{
    public function testItKnowsNoClientsOfAnUnknownSession(): void
    {
        // Arrange
        $links = new SessionLinks(new SqlSessions(Connection::sqlite(':memory:'), 3600));

        // Act
        $clients = $links->clientsOf('nope');

        // Assert
        self::assertSame([], $clients);
    }
}
