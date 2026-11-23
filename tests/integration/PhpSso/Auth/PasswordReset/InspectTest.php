<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\PasswordReset;

use PhpSso\Auth\PasswordReset;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(PasswordReset::class, 'inspect')]
#[CoversMethod(PasswordReset::class, 'hash')]
final class InspectTest extends TestCase
{
    public function testAWorkingLinkNamesThePersonAndAnythingElseNamesNoOne(): void
    {
        // Arrange
        $app = new Support();
        $app->data->user('alice');
        $token = $app->link();

        // Act
        $person = $app->service->inspect($token);
        $garbage = $app->service->inspect('not-a-token');

        // Assert
        $this->assertSame('alice', $person['username'] ?? null);
        $this->assertNull($garbage);
    }

    public function testOnlyTheHashOfTheTokenIsKept(): void
    {
        // Arrange
        $app = new Support();
        $app->data->user('alice');
        $token = $app->link();

        // Act
        $rows = $app->data->db->pdo->query('SELECT token_hash FROM password_resets WHERE token_hash IS NOT NULL')->fetchAll(\PDO::FETCH_COLUMN);

        // Assert
        $this->assertSame([PasswordReset::hash($token)], $rows);
        $this->assertNotContains($token, $rows);
        $this->assertSame(64, strlen($rows[0]));
    }

    public function testATokenIsLongAndDifferentEachTime(): void
    {
        // Arrange
        $app = new Support();
        $app->data->user('alice');

        // Act
        $tokens = [$app->link('alice', '203.0.113.1'), $app->link('alice', '203.0.113.2'), $app->link('alice', '203.0.113.3')];

        // Assert
        $this->assertCount(3, array_unique($tokens));
        foreach ($tokens as $token) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        }
    }
}
