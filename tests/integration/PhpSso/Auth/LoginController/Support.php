<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\LoginController;

use PhpSso\Auth\Actions\AcceptConsentsAction;
use PhpSso\Auth\Actions\ChangePasswordAction;
use PhpSso\Auth\Actions\RequiredActions;
use PhpSso\Auth\Identifier\IdentifierResolverFactory;
use PhpSso\Auth\LoginController;
use PhpSso\Auth\LoginFlow;
use PhpSso\Auth\Mfa\Mfa;
use PhpSso\Auth\Mfa\MfaChallenge;
use PhpSso\Auth\Mfa\SecretBox;
use PhpSso\Auth\MfaLoginController;
use PhpSso\Auth\RequiredActionController;
use PhpSso\Auth\Social\SocialProviders;
use PhpSso\Auth\LoginThrottle;
use PhpSso\Net\ClientIp;
use PhpSso\Pages;
use PhpSso\Store\Consents;
use PhpSso\Store\LoginAttempts;
use PhpSso\Store\MfaFactors;
use PhpSso\Store\SamlConnections;
use PhpSso\Store\Sessions;
use PhpSso\Store\Settings;
use Laminas\Diactoros\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Tests\integration\PhpSso\Support\TestData;
use PhpSso\Store\Sql\SqlConsents;
use PhpSso\Store\Sql\SqlLoginAttempts;
use PhpSso\Store\Sql\SqlMfaFactors;
use PhpSso\Store\Sql\SqlSamlConnections;
use PhpSso\Store\Sql\SqlSessions;
use PhpSso\Store\Sql\SqlSettings;

/** The sign-in controller on an in-memory database, with the real built-in pages. */
final class Support
{
    public readonly TestData $data;
    public readonly LoginAttempts $attempts;
    public readonly Sessions $sessions;
    public readonly Consents $consents;
    public readonly SamlConnections $saml;
    public readonly \PhpSso\Store\IpRules $ipRules;
    public readonly RequiredActions $actions;
    public readonly LoginFlow $flow;
    public readonly RequiredActionController $requiredController;
    public readonly LoginThrottle $throttle;
    public readonly Mfa $mfa;
    public readonly MfaLoginController $mfaController;
    public readonly LoginController $controller;
    private string $keyFile;

    /** @param array{enabled?: bool, per_identifier?: int, per_ip?: int, window_seconds?: int} $throttle */
    public function __construct(array $throttle = [], string $mfaPolicy = 'optional', ?SocialProviders $social = null, string $identifier = 'username')
    {
        $this->data = new TestData();
        $this->attempts = new SqlLoginAttempts($this->data->db);
        $this->sessions = new SqlSessions($this->data->db, 3600);
        $this->consents = new SqlConsents($this->data->db);
        $this->saml = new SqlSamlConnections($this->data->db);
        $this->ipRules = new \PhpSso\Store\Sql\SqlIpRules($this->data->db);
        $this->actions = new RequiredActions([new AcceptConsentsAction($this->consents), new ChangePasswordAction($this->data->users, $this->sessions)]);
        $this->flow = new LoginFlow($this->sessions, $this->attempts, $this->actions, static fn (): bool => true);
        $root = dirname(__DIR__, 5);
        $pages = new Pages($root . '/templates/pages', '/nonexistent', sys_get_temp_dir() . '/sso-login-test-' . getmypid(), $root . '/public', ['app_name' => 'SSO'], true);
        $this->throttle = new LoginThrottle($this->attempts, new SqlSettings($this->data->db), $throttle);
        $this->keyFile = sys_get_temp_dir() . '/sso-login-test-' . bin2hex(random_bytes(4)) . '.key';
        $this->mfa = new Mfa(new SqlMfaFactors($this->data->db), new SecretBox($this->keyFile), new SqlSettings($this->data->db), 'Test SSO', $mfaPolicy);
        $this->controller = new LoginController($pages, $this->flow, IdentifierResolverFactory::create($identifier, $this->data->users), $this->data->users, $this->attempts, new ClientIp([]), $this->throttle, $this->mfa, $social ?? new SocialProviders(), $this->saml, new \PhpSso\IpPolicy($this->ipRules));
        $this->mfaController = new MfaLoginController($pages, $this->flow, $this->mfa, new MfaChallenge($this->mfa, $this->throttle, $this->attempts), $this->data->users, new ClientIp([]));
        $this->requiredController = new RequiredActionController($pages, $this->flow, $this->actions, $this->data->users, new ClientIp([]));
        $_SESSION = ['csrf' => 'token'];
    }

    public function __destruct()
    {
        @unlink($this->keyFile);
    }

    /**
     * A person with the authenticator app set up a minute ago, so a code for the current step is still new.
     *
     * @return array{0: array<string, mixed>, 1: string, 2: string[]} the user row, the secret and the recovery codes
     */
    public function enrolledUser(string $username = 'alice', bool $admin = false): array
    {
        $sub = $this->data->users->create(['username' => $username, 'password' => 'secret-pass', 'name' => ucfirst($username), 'email' => $username . '@example.com', 'email_verified' => true, 'is_admin' => $admin]);
        $user = $this->data->users->find($sub);
        $secret = $this->mfa->newSecret();
        $codes = $this->mfa->enroll($user, $secret, \PhpSso\Auth\Mfa\Totp::code($secret, intdiv(time() - 60, 30)), time() - 60);

        return [$user, $secret, $codes];
    }

    /** A code for the current step. */
    public static function codeFor(string $secret): string
    {
        return \PhpSso\Auth\Mfa\Totp::code($secret, intdiv(time(), 30));
    }

    /** @param array<string, string> $body */
    public static function post(array $body, string $ip = '203.0.113.9'): ServerRequestInterface
    {
        return (new ServerRequest(['REMOTE_ADDR' => $ip], [], '/login', 'POST'))->withParsedBody($body + ['_csrf' => 'token']);
    }
}
