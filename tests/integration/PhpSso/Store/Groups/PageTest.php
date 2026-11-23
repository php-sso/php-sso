<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Groups;

use PhpSso\Store\Groups;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;

#[CoversMethod(Groups::class, 'page')]
final class PageTest extends TestCase
{
    public function testItListsGroupsByNameWithTheirMemberCounts(): void
    {
        // Arrange
        $data = new TestData();
        $alice = $data->user('alice');
        $bob = $data->user('bob');
        $data->group('Staff', [$alice, $bob]);
        $data->group('Admins', [$alice]);
        $data->group('Empty');

        // Act
        $page = $data->groups->page('', 10, 0);

        // Assert
        $this->assertSame(['Admins', 'Empty', 'Staff'], array_column($page, 'name'));
        $this->assertSame([1, 0, 2], array_map('intval', array_column($page, 'members')));
    }

    public function testItReturnsOnlyTheRequestedPage(): void
    {
        // Arrange
        $data = new TestData();
        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            $data->group($name);
        }

        // Act
        $page = $data->groups->page('', 2, 2);

        // Assert
        $this->assertSame(['c', 'd'], array_column($page, 'name'));
    }

    public function testTextMatchesTheNameOrTheDescriptionIgnoringCase(): void
    {
        // Arrange
        $data = new TestData();
        $data->groups->create('Finance', 'People who pay');
        $data->groups->create('Staff', 'Everyone at ACME');
        $data->groups->create('Other', '');

        // Act
        $byName = $data->groups->page('FIN', 10, 0);
        $byDescription = $data->groups->page('acme', 10, 0);

        // Assert
        $this->assertSame(['Finance'], array_column($byName, 'name'));
        $this->assertSame(['Staff'], array_column($byDescription, 'name'));
    }
}
