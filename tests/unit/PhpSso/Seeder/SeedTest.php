<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Seeder;

use PhpSso\Seeder;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(Seeder::class, 'seed')]
final class SeedTest extends TestCase
{
    public function testItCreatesRolesOnceAndGivesThemToGroupsAndUsers(): void
    {
        // Arrange
        $data = new TestData();
        $seeder = new Seeder($data->users, $data->groups, $data->clients, $data->roles);
        $seed = [
            'groups' => ['staff' => 'All employees'],
            'users' => [
                'admin' => ['password' => 'admin-pass', 'name' => 'Administrator', 'email' => 'admin@example.com', 'is_admin' => true],
                'alice' => ['password' => 'alice-pass', 'name' => 'Alice', 'email' => 'alice@example.com', 'groups' => ['staff']],
            ],
            'roles' => [
                'editor' => ['description' => 'May edit', 'groups' => ['staff']],
                'admin' => ['users' => ['admin'], 'clients' => ['app']],
            ],
            'clients' => [
                'app' => ['name' => 'App', 'secret' => null, 'redirect_uris' => ['http://localhost/cb'], 'grants' => ['authorization_code']],
                'other' => ['name' => 'Other', 'secret' => null, 'redirect_uris' => ['http://localhost/cb'], 'grants' => ['authorization_code']],
            ],
        ];

        // Act
        $seeder->seed($seed);

        // Assert
        $alice = $data->users->findByUsername('alice');
        $admin = $data->users->findByUsername('admin');
        $this->assertSame(['editor'], $data->roles->effectiveFor($alice['sub'], 'app'));
        $this->assertSame(['admin'], $data->roles->effectiveFor($admin['sub'], 'app'));
        $this->assertSame([], $data->roles->effectiveFor($admin['sub'], 'other'));
        $this->assertSame(['editor'], $data->roles->effectiveFor($alice['sub'], 'other'));
    }

    public function testASeedWithoutRolesCreatesNone(): void
    {
        // Arrange
        $data = new TestData();
        $seeder = new Seeder($data->users, $data->groups, $data->clients, $data->roles);
        $seed = [
            'groups' => [],
            'users' => ['admin' => ['password' => 'admin-pass', 'name' => 'Administrator', 'email' => 'admin@example.com', 'is_admin' => true]],
            'clients' => ['app' => ['name' => 'App', 'secret' => null, 'redirect_uris' => ['http://localhost/cb'], 'grants' => ['authorization_code']]],
        ];

        // Act
        $seeder->seed($seed);

        // Assert
        $this->assertSame([], $data->roles->all());
    }

    public function testItRefusesAWeakFirstAdministratorPasswordWhenAskedToCheck(): void
    {
        // Arrange
        $data = new TestData();
        $seeder = new Seeder($data->users, $data->groups, $data->clients, $data->roles);
        $seed = ['groups' => [], 'clients' => [], 'check_passwords' => true,
            'users' => ['admin' => ['password' => 'admin123', 'name' => 'Administrator', 'email' => 'admin@example.com', 'is_admin' => true]]];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('too short');

        // Act
        $seeder->seed($seed);

        // Assert (the exception above)
    }

    public function testItRefusesAFirstAdministratorUsernameWithUnsafeCharactersWhenAskedToCheck(): void
    {
        // Arrange
        $data = new TestData();
        $seeder = new Seeder($data->users, $data->groups, $data->clients, $data->roles);
        $seed = ['groups' => [], 'clients' => [], 'check_passwords' => true,
            'users' => ['bad name/<x>' => ['password' => 'a-long-enough-password-9', 'name' => 'Administrator', 'email' => 'admin@example.com', 'is_admin' => true]]];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ADMIN_USERNAME');

        // Act
        $seeder->seed($seed);

        // Assert (the exception above)
    }

    public function testItCreatesTheFirstAdministratorUnderTheGivenUsername(): void
    {
        // Arrange
        $data = new TestData();
        $seeder = new Seeder($data->users, $data->groups, $data->clients, $data->roles);
        $seed = ['groups' => [], 'clients' => [], 'check_passwords' => true,
            'users' => ['walter' => ['password' => 'a-long-enough-password-9', 'name' => 'Administrator', 'email' => 'admin@example.com', 'is_admin' => true]]];

        // Act
        $seeder->seed($seed);

        // Assert
        $this->assertNotNull($data->users->findByUsername('walter'));
        $this->assertNull($data->users->findByUsername('admin'));
    }

    public function testItRefusesAPasswordThatRepeatsTheSameFewCharacters(): void
    {
        // Arrange
        $data = new TestData();
        $seeder = new Seeder($data->users, $data->groups, $data->clients, $data->roles);
        $seed = ['groups' => [], 'clients' => [], 'check_passwords' => true,
            'users' => ['admin' => ['password' => 'abababababababab', 'name' => 'Administrator', 'email' => 'admin@example.com', 'is_admin' => true]]];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('too simple');

        // Act
        $seeder->seed($seed);

        // Assert (the exception above)
    }

    public function testItAcceptsALongPassword(): void
    {
        // Arrange
        $data = new TestData();
        $seeder = new Seeder($data->users, $data->groups, $data->clients, $data->roles);
        $strong = ['groups' => [], 'clients' => [], 'check_passwords' => true,
            'users' => ['admin' => ['password' => 'correct-horse-battery', 'name' => 'Administrator', 'email' => 'admin@example.com', 'is_admin' => true]]];

        // Act
        $seeder->seed($strong);

        // Assert
        $this->assertNotNull($data->users->findByUsername('admin'));
    }
}
