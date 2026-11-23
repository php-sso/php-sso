<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\Actions\AcceptConsentsAction;

use PhpSso\Auth\Actions\AcceptConsentsAction;
use PhpSso\Store\Consents;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;
use PhpSso\Store\Sql\SqlConsents;

#[CoversMethod(AcceptConsentsAction::class, 'submit')]
final class SubmitTest extends TestCase
{
    private TestData $data;
    private Consents $consents;
    private AcceptConsentsAction $action;
    private int $id;
    private array $user;

    protected function setUp(): void
    {
        $this->data = new TestData();
        $this->consents = new SqlConsents($this->data->db);
        $this->action = new AcceptConsentsAction($this->consents);
        $this->id = $this->consents->create('Terms', 'a', null, true);
        $this->user = $this->data->users->find($this->data->user('alice'));
    }

    public function testTickingTheBoxAcceptsIt(): void
    {
        // Arrange
        $body = ['consent_id' => (string) $this->id, 'version' => '1', 'accept' => '1'];

        // Act
        $error = $this->action->submit($this->user, $body, '203.0.113.9');

        // Assert
        $this->assertNull($error);
        $this->assertSame([], $this->consents->pendingFor($this->user['sub']));
    }

    public function testWithoutTheBoxNothingIsAccepted(): void
    {
        // Arrange
        $body = ['consent_id' => (string) $this->id, 'version' => '1'];

        // Act
        $error = $this->action->submit($this->user, $body, '203.0.113.9');

        // Assert
        $this->assertSame('Tick the box to continue.', $error);
        $this->assertCount(1, $this->consents->pendingFor($this->user['sub']));
    }

    public function testAPageThatWasOpenWhenTheVersionChangedIsNotAccepted(): void
    {
        // Arrange
        $body = ['consent_id' => (string) $this->id, 'version' => '1', 'accept' => '1'];
        $this->consents->bump($this->id);

        // Act
        $error = $this->action->submit($this->user, $body, '203.0.113.9');

        // Assert
        $this->assertStringContainsString('has changed', (string) $error);
        $this->assertCount(1, $this->consents->pendingFor($this->user['sub']));
    }

    public function testACheckForADifferentNoticeThanTheOneAskedIsNotAccepted(): void
    {
        // Arrange
        $other = $this->consents->create('Policy', 'b', null, true);
        $body = ['consent_id' => (string) $other, 'version' => '1', 'accept' => '1'];

        // Act
        $error = $this->action->submit($this->user, $body, '203.0.113.9');

        // Assert
        $this->assertStringContainsString('has changed', (string) $error);
        $this->assertCount(2, $this->consents->pendingFor($this->user['sub']));
    }

    public function testTheAddressIsKeptWithTheAcceptance(): void
    {
        // Arrange
        $body = ['consent_id' => (string) $this->id, 'version' => '1', 'accept' => '1'];

        // Act
        $this->action->submit($this->user, $body, '198.51.100.7');

        // Assert
        $this->assertSame('198.51.100.7', $this->data->db->pdo->query('SELECT ip FROM user_consents')->fetchColumn());
    }
}
