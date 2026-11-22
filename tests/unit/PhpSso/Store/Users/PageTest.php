<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Users;

use PhpSso\Store\Users;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(Users::class, 'page')]
final class PageTest extends TestCase
{
    private function people(): TestData
    {
        $data = new TestData();
        foreach (['carol', 'alice', 'bob', 'dave'] as $name) {
            $data->user($name);
        }

        return $data;
    }

    public function testItListsPeopleByUsername(): void
    {
        // Arrange
        $data = $this->people();

        // Act
        $page = $data->users->page('', 10, 0);

        // Assert
        $this->assertSame(['alice', 'bob', 'carol', 'dave'], array_column($page, 'username'));
    }

    public function testItReturnsOnlyTheRequestedPage(): void
    {
        // Arrange
        $data = $this->people();

        // Act
        $second = $data->users->page('', 2, 2);

        // Assert
        $this->assertSame(['carol', 'dave'], array_column($second, 'username'));
    }

    public function testAPageBeyondTheLastIsEmpty(): void
    {
        // Arrange
        $data = $this->people();

        // Act
        $page = $data->users->page('', 2, 40);

        // Assert
        $this->assertSame([], $page);
    }

    public function testEachPersonHasTheirGroupNamesInOrderAndNoneIsAnEmptyList(): void
    {
        // Arrange
        $data = $this->people();
        $alice = $data->users->findByUsername('alice')['sub'];
        $bob = $data->users->findByUsername('bob')['sub'];
        $data->group('Zeta', [$alice, $bob]);
        $data->group('Alpha', [$alice]);

        // Act
        $page = array_column($data->users->page('', 10, 0), null, 'username');

        // Assert
        $this->assertSame(['Alpha', 'Zeta'], $page['alice']['groups']);
        $this->assertSame(['Zeta'], $page['bob']['groups']);
        $this->assertSame([], $page['carol']['groups']);
    }

    public function testTextMatchesNameUsernameOrEmailIgnoringCase(): void
    {
        // Arrange
        $data = $this->people();

        // Act
        $byUsername = $data->users->page('CAR', 10, 0);
        $byEmail = $data->users->page('BOB@example', 10, 0);

        // Assert
        $this->assertSame(['carol'], array_column($byUsername, 'username'));
        $this->assertSame(['bob'], array_column($byEmail, 'username'));
    }

    public function testAWildcardInTheTextIsLiteral(): void
    {
        // Arrange
        $data = $this->people();

        // Act
        $page = $data->users->page('%', 10, 0);

        // Assert
        $this->assertSame([], $page);
    }

    public function testTextCountsOnlyWhatMatchesAndStillPages(): void
    {
        // Arrange
        $data = $this->people();

        // Act
        $page = $data->users->page('@example.com', 2, 1);

        // Assert
        $this->assertSame(['bob', 'carol'], array_column($page, 'username'));
    }
}
