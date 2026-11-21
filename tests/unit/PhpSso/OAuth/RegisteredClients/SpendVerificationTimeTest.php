<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\RegisteredClients;

use PhpSso\Store\Sql\Connection;
use PhpSso\OAuth\RegisteredClients;
use PhpSso\Store\Clients;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use PhpSso\Store\Sql\SqlClients;

#[CoversMethod(RegisteredClients::class, 'spendVerificationTime')]
final class SpendVerificationTimeTest extends TestCase
{
    public function testItTakesAboutAsLongAsCheckingARealSecretHash(): void
    {
        // Arrange
        $clients = new RegisteredClients(new SqlClients(Connection::sqlite(':memory:')));
        $clients->spendVerificationTime('warm up'); // the first call also creates the hash
        $real = password_hash('x', PASSWORD_DEFAULT);
        $start = hrtime(true);
        password_verify('y', $real);
        $reference = hrtime(true) - $start;

        // Act
        $start = hrtime(true);
        $clients->spendVerificationTime('guess');
        $spent = hrtime(true) - $start;

        // Assert
        self::assertGreaterThan($reference / 4, $spent);
    }
}
