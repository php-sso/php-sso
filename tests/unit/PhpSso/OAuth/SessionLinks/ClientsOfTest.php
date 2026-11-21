<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\SessionLinks;

use PhpSso\OAuth\SessionLinks;
use PhpSso\Store\Sessions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use PhpSso\Store\Sql\SqlSessions;
use Tests\unit\PhpSso\Support\TestConnection;

#[CoversMethod(SessionLinks::class, 'clientsOf')]
final class ClientsOfTest extends TestCase
{
    public function testItKnowsNoClientsOfAnUnknownSession(): void
    {
        // Arrange
        $links = new SessionLinks(new SqlSessions(TestConnection::open(), 3600));

        // Act
        $clients = $links->clientsOf('nope');

        // Assert
        self::assertSame([], $clients);
    }
}
