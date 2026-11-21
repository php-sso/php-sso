<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\Actions\AcceptConsentsAction;

use PhpSso\Auth\Actions\AcceptConsentsAction;
use PhpSso\Store\Consents;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(AcceptConsentsAction::class, 'applies')]
#[CoversMethod(AcceptConsentsAction::class, 'page')]
final class AppliesTest extends TestCase
{
    public function testItAppliesWhileSomethingIsNotAccepted(): void
    {
        // Arrange
        $data = new TestData();
        $consents = new Consents($data->db);
        $consents->create('Terms', 'a', null, true);
        $user = $data->users->find($data->user('alice'));

        // Act
        $applies = (new AcceptConsentsAction($consents))->applies($user);

        // Assert
        $this->assertTrue($applies);
    }

    public function testItDoesNotApplyWhenThereAreNoConsents(): void
    {
        // Arrange
        $data = new TestData();
        $user = $data->users->find($data->user('alice'));

        // Act
        $applies = (new AcceptConsentsAction(new Consents($data->db)))->applies($user);

        // Assert
        $this->assertFalse($applies);
    }

    public function testThePageShowsTheFirstPendingConsentAndHowManyAreLeft(): void
    {
        // Arrange
        $data = new TestData();
        $consents = new Consents($data->db);
        $first = $consents->create('Terms', 'a', 'https://example.com/t', true);
        $consents->create('Policy', 'b', null, true);
        $user = $data->users->find($data->user('alice'));

        // Act
        $page = (new AcceptConsentsAction($consents))->page($user);

        // Assert
        $this->assertSame('consent', $page['template']);
        $this->assertSame($first, $page['vars']['consent']['id']);
        $this->assertSame(2, $page['vars']['step']['of']);
    }
}
