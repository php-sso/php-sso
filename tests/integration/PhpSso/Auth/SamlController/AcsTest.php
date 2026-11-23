<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\SamlController;

use PhpSso\Auth\SamlController;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(SamlController::class, 'acs')]
final class AcsTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function alice(Support $app, bool $verified = true): string
    {
        return $app->login->data->users->create(['username' => 'alice', 'password' => 'secret-pass', 'name' => 'Alice', 'email' => 'alice@acme.com', 'email_verified' => $verified, 'is_admin' => false]);
    }

    public function testSomeoneConnectedBeforeIsSignedInAndSentWhereTheyWereGoing(): void
    {
        // Arrange
        $app = new Support();
        $sub = $this->alice($app);
        $app->identities->link($sub, 'saml-' . $app->saml->id, 'alice-at-acme', 'alice@acme.com');
        $app->saml->sent();

        // Act
        $response = $app->controller->acs($app->post($app->saml->answer()), $app->saml->id);

        // Assert
        $this->assertSame('/account', $response->getHeaderLine('Location'));
        $this->assertSame($sub, $_SESSION['user']);
        $this->assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'));
    }

    public function testSomeoneNotConnectedIsRefusedByDefault(): void
    {
        // Arrange
        $app = new Support();
        $this->alice($app);
        $app->saml->sent();

        // Act
        $response = $app->controller->acs($app->post($app->saml->answer()), $app->saml->id);

        // Assert
        $this->assertSame('/login', $response->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('user', $_SESSION);
        $this->assertStringContainsString('No account here is connected', $_SESSION['login_error']);
    }

    public function testAnExistingAccountWithTheSameVerifiedAddressIsConnectedWhenAllowed(): void
    {
        // Arrange
        $app = new Support();
        $app->allow(true, false);
        $sub = $this->alice($app);
        $app->saml->sent();

        // Act
        $response = $app->controller->acs($app->post($app->saml->answer()), $app->saml->id);

        // Assert
        $this->assertSame('/account', $response->getHeaderLine('Location'));
        $this->assertSame($sub, $_SESSION['user']);
        $this->assertSame('alice-at-acme', $app->identities->forUser($sub)[0]['subject']);
    }

    public function testAnExistingAccountWhoseAddressWeNeverVerifiedIsNotTakenOver(): void
    {
        // Arrange
        $app = new Support();
        $app->allow(true, false);
        $this->alice($app, false);
        $app->saml->sent();

        // Act
        $response = $app->controller->acs($app->post($app->saml->answer()), $app->saml->id);

        // Assert
        $this->assertSame('/login', $response->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function testANewPersonIsCreatedWithoutAPasswordWhenAllowed(): void
    {
        // Arrange
        $app = new Support();
        $app->allow(false, true);
        $app->saml->sent();

        // Act
        $response = $app->controller->acs($app->post($app->saml->answer()), $app->saml->id);

        // Assert
        $user = $app->login->data->users->findByEmail('alice@acme.com');
        $this->assertNotNull($user);
        $this->assertNull($user['password_hash']);
        $this->assertFalse((bool) $user['is_admin']);
        $this->assertSame($user['sub'], $_SESSION['user']);
        $this->assertSame('/account', $response->getHeaderLine('Location'));
    }

    public function testNobodyIsCreatedForAnAddressTheCompanyMayNotVouchFor(): void
    {
        // Arrange
        $app = new Support();
        $app->allow(true, true);
        $app->saml->sent();
        $answer = $app->saml->answer(['attributes' => ['email' => 'ceo@other-company.com']]);

        // Act
        $response = $app->controller->acs($app->post($answer), $app->saml->id);

        // Assert
        $this->assertSame('/login', $response->getHeaderLine('Location'));
        $this->assertNull($app->login->data->users->findByEmail('ceo@other-company.com'));
        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function testTheAllowedDomainsRuleAlsoCoversCompanyAccounts(): void
    {
        // Arrange
        $app = new Support('https://sso.test', true, ['allowed_domains' => ['somewhere-else.org']]);
        $app->allow(false, true);
        $app->saml->sent();

        // Act
        $response = $app->controller->acs($app->post($app->saml->answer()), $app->saml->id);

        // Assert
        $this->assertSame('/login', $response->getHeaderLine('Location'));
        $this->assertNull($app->login->data->users->findByEmail('alice@acme.com'));
    }

    public function testALockedAccountIsNotSignedIn(): void
    {
        // Arrange
        $app = new Support();
        $sub = $this->alice($app);
        $app->identities->link($sub, 'saml-' . $app->saml->id, 'alice-at-acme', 'alice@acme.com');
        $app->login->data->users->setLocked($sub, true);
        $app->saml->sent();

        // Act
        $response = $app->controller->acs($app->post($app->saml->answer()), $app->saml->id);

        // Assert
        $this->assertSame('/login', $response->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('user', $_SESSION);
        $this->assertStringContainsString('locked', $_SESSION['login_error']);
    }

    public function testTheCompanyIsAFirstFactorSoLocalTwoStepStillApplies(): void
    {
        // Arrange
        $app = new Support();
        [$user] = $app->login->enrolledUser('alice');
        $app->identities->link($user['sub'], 'saml-' . $app->saml->id, 'alice-at-acme', 'alice@acme.com');
        $app->saml->sent();

        // Act
        $response = $app->controller->acs($app->post($app->saml->answer()), $app->saml->id);

        // Assert
        $this->assertSame('/login/mfa', $response->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function testAnAnswerThatDoesNotCheckOutSignsNobodyIn(): void
    {
        // Arrange
        $app = new Support();
        $sub = $this->alice($app);
        $app->identities->link($sub, 'saml-' . $app->saml->id, 'alice-at-acme', 'alice@acme.com');
        $app->saml->sent();

        // Act
        $response = $app->controller->acs($app->post($app->saml->answer(['tamper' => true])), $app->saml->id);

        // Assert
        $this->assertSame('/login', $response->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function testACompanySignInIsRecordedAsFedNotAsAPassword(): void
    {
        // Arrange
        $app = new Support();
        $sub = $this->alice($app);
        $app->identities->link($sub, 'saml-' . $app->saml->id, 'alice-at-acme', 'alice@acme.com');
        $app->saml->sent();

        // Act
        $app->controller->acs($app->post($app->saml->answer()), $app->saml->id);

        // Assert
        $this->assertSame(['fed'], $app->login->sessions->amr((string) $_SESSION['sid']));
    }
}
