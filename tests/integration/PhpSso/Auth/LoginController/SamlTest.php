<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\LoginController;

use PhpSso\Auth\LoginController;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Auth\Saml\Support as SamlSupport;

#[CoversMethod(LoginController::class, 'identify')]
#[CoversMethod(LoginController::class, 'password')]
final class SamlTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAnAddressAtAClaimedDomainGoesStraightToTheCompany(): void
    {
        // Arrange
        $app = new Support([], 'optional', null, 'email');
        $company = new SamlSupport(data: $app->data);

        // Act
        $response = $app->controller->identify(Support::post(['username' => 'Alice@Acme.com']));

        // Assert
        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/login/saml/' . $company->id, $response->getHeaderLine('Location'));
        $this->assertNull($app->flow->pending());
    }

    public function testAnAddressElsewhereGoesToThePasswordPageAsBefore(): void
    {
        // Arrange
        $app = new Support([], 'optional', null, 'email');
        new SamlSupport(data: $app->data);

        // Act
        $response = $app->controller->identify(Support::post(['username' => 'bob@elsewhere.com']));

        // Assert
        $this->assertSame('/login/password', $response->getHeaderLine('Location'));
    }

    public function testASwitchedOffConnectionSendsNobodyToTheCompany(): void
    {
        // Arrange
        $app = new Support([], 'optional', null, 'email');
        new SamlSupport(enabled: false, data: $app->data);

        // Act
        $response = $app->controller->identify(Support::post(['username' => 'alice@acme.com']));

        // Assert
        $this->assertSame('/login/password', $response->getHeaderLine('Location'));
    }

    public function testWhenPeopleSignInByUsernameNobodyIsSentAwayByAnAddress(): void
    {
        // Arrange
        $app = new Support();
        new SamlSupport(data: $app->data);

        // Act
        $response = $app->controller->identify(Support::post(['username' => 'alice@acme.com']));

        // Assert
        $this->assertSame('/login/password', $response->getHeaderLine('Location'));
    }

    public function testAPersonAtAClaimedDomainCannotSignInWithAPassword(): void
    {
        // Arrange
        $app = new Support([], 'optional', null, 'email');
        new SamlSupport(data: $app->data);
        $sub = $app->data->users->create(['username' => 'alice', 'password' => 'secret-pass', 'name' => 'Alice', 'email' => 'alice@acme.com', 'email_verified' => true, 'is_admin' => false]);
        $app->flow->begin('alice@acme.com', $sub);

        // Act
        $response = $app->controller->password(Support::post(['password' => 'secret-pass']));

        // Assert
        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('Invalid credentials', (string) $response->getBody());
        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function testThatPersonSignsInWithTheirPasswordAgainOnceTheConnectionIsOff(): void
    {
        // Arrange
        $app = new Support([], 'optional', null, 'email');
        $company = new SamlSupport(enabled: false, data: $app->data);
        $sub = $app->data->users->create(['username' => 'alice', 'password' => 'secret-pass', 'name' => 'Alice', 'email' => 'alice@acme.com', 'email_verified' => true, 'is_admin' => false]);
        $app->flow->begin('alice@acme.com', $sub);

        // Act
        $response = $app->controller->password(Support::post(['password' => 'secret-pass']));

        // Assert
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame($sub, $_SESSION['user']);
        $this->assertNotNull($company);
    }
}
